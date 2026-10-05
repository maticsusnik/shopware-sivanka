#!/usr/bin/env python3
"""
Derive Shopware product properties from product names/descriptions and write them
back through the Admin API.

Workflow:
    ./sw_props.py fetch                 # pull all products into products.json
    ./sw_props.py extract               # apply rules.json -> assignments.csv + report
    ./sw_props.py apply --dry-run       # show what would be written
    ./sw_props.py apply                 # create groups/options + link products

Credentials come from the environment (or --url/--id/--secret):
    SW_API_URL, SW_API_ID, SW_API_SECRET
"""

import argparse
import csv
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from collections import Counter, defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_RULES = os.path.join(HERE, "rules.json")
DEFAULT_PRODUCTS = os.path.join(HERE, "products.json")
DEFAULT_ASSIGNMENTS = os.path.join(HERE, "assignments.csv")

# ---------------------------------------------------------------- API client


class Api:
    def __init__(self, base_url, client_id, client_secret):
        self.base = base_url.rstrip("/")
        if self.base.endswith("/api"):
            self.base = self.base[: -len("/api")]
        self.client_id = client_id
        self.client_secret = client_secret
        self.token = None
        self.token_expires = 0

    def _authenticate(self):
        body = json.dumps(
            {
                "grant_type": "client_credentials",
                "client_id": self.client_id,
                "client_secret": self.client_secret,
            }
        ).encode()
        req = urllib.request.Request(
            f"{self.base}/api/oauth/token",
            data=body,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=60) as res:
            data = json.load(res)
        self.token = data["access_token"]
        self.token_expires = time.time() + int(data.get("expires_in", 600)) - 30

    def request(self, method, path, payload=None, extra_headers=None):
        if not self.token or time.time() >= self.token_expires:
            self._authenticate()
        headers = {
            "Authorization": f"Bearer {self.token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        if extra_headers:
            headers.update(extra_headers)
        data = json.dumps(payload).encode() if payload is not None else None
        req = urllib.request.Request(
            f"{self.base}{path}", data=data, headers=headers, method=method
        )
        try:
            with urllib.request.urlopen(req, timeout=300) as res:
                raw = res.read()
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode(errors="replace")
            raise RuntimeError(
                f"{method} {path} -> HTTP {exc.code}\n{detail[:4000]}"
            ) from None


def api_from_args(args):
    url = args.url or os.environ.get("SW_API_URL")
    cid = args.id or os.environ.get("SW_API_ID")
    secret = args.secret or os.environ.get("SW_API_SECRET")
    missing = [
        n
        for n, v in (("--url/SW_API_URL", url), ("--id/SW_API_ID", cid), ("--secret/SW_API_SECRET", secret))
        if not v
    ]
    if missing:
        sys.exit("Missing credentials: " + ", ".join(missing))
    return Api(url, cid, secret)


# ---------------------------------------------------------------- fetch


def cmd_fetch(args):
    api = api_from_args(args)
    products = []
    page = 1
    while True:
        res = api.request(
            "POST",
            "/api/search/product",
            {
                "page": page,
                "limit": args.batch,
                "filter": [{"type": "equals", "field": "parentId", "value": None}],
                "sort": [{"field": "productNumber", "order": "ASC"}],
                "includes": {
                    "product": [
                        "id",
                        "productNumber",
                        "translated",
                        "name",
                        "description",
                        "propertyIds",
                    ]
                },
                "total-count-mode": 1 if page == 1 else 0,
            },
        )
        rows = res.get("data", [])
        for row in rows:
            translated = row.get("translated") or {}
            products.append(
                {
                    "id": row["id"],
                    "productNumber": row.get("productNumber"),
                    "name": translated.get("name") or row.get("name") or "",
                    "description": translated.get("description")
                    or row.get("description")
                    or "",
                    "propertyIds": row.get("propertyIds") or [],
                }
            )
        print(f"  page {page}: {len(rows)} products (total so far {len(products)})")
        if len(rows) < args.batch:
            break
        page += 1

    with open(args.products, "w", encoding="utf-8") as fh:
        json.dump(products, fh, ensure_ascii=False, indent=1)
    print(f"Wrote {len(products)} products -> {args.products}")


# ---------------------------------------------------------------- extraction

TAG_RE = re.compile(r"<[^>]+>")
WS_RE = re.compile(r"\s+")


def clean_text(value):
    if not value:
        return ""
    text = TAG_RE.sub(" ", value)
    text = text.replace("&nbsp;", " ").replace("&amp;", "&")
    return WS_RE.sub(" ", text).strip()


def haystack(product, source):
    name = clean_text(product.get("name"))
    if source == "name+description":
        return f"{name} . {clean_text(product.get('description'))}"
    return name


def normalise_number(raw):
    value = raw.replace(",", ".").strip()
    try:
        num = float(value)
    except ValueError:
        return raw
    if num == int(num):
        return str(int(num))
    return ("%g" % num).replace(".", ",")


def normalise_dimension(raw):
    parts = re.split(r"[x×]", raw, flags=re.IGNORECASE)
    return "x".join(p.strip() for p in parts if p.strip())


def load_rules(path):
    with open(path, encoding="utf-8") as fh:
        cfg = json.load(fh)
    compiled = []
    for group in cfg["groups"]:
        entry = dict(group)
        if group["kind"] == "keyword":
            entry["rules"] = [
                (option, re.compile(pattern, re.IGNORECASE | re.UNICODE))
                for option, pattern in group["rules"]
            ]
        else:
            entry["regex"] = re.compile(group["pattern"], re.IGNORECASE | re.UNICODE)
        compiled.append(entry)
    return compiled


def extract_for_product(product, groups):
    """Return {group_name: [option, ...]} for a single product."""
    result = defaultdict(list)
    for group in groups:
        text = haystack(product, group.get("source", "name"))
        if not text:
            continue
        if group["kind"] == "keyword":
            for option, regex in group["rules"]:
                if regex.search(text):
                    if option not in result[group["group"]]:
                        result[group["group"]].append(option)
                    if group.get("first_only"):
                        break
        else:
            unit = group.get("unit", "")
            found = []
            for match in group["regex"].finditer(text):
                raw = match.group(1)
                if group.get("normalise") == "dimension":
                    value = normalise_dimension(raw)
                else:
                    value = normalise_number(raw)
                label = f"{value} {unit}".strip()
                if label not in found:
                    found.append(label)
            limit = group.get("max_per_product")
            if limit:
                found = found[:limit]
            for label in found:
                result[group["group"]].append(label)
    return {k: v for k, v in result.items() if v}


def cmd_extract(args):
    groups = load_rules(args.rules)
    with open(args.products, encoding="utf-8") as fh:
        products = json.load(fh)

    rows = []
    per_group = defaultdict(Counter)
    covered = 0
    untouched = []
    for product in products:
        assigned = extract_for_product(product, groups)
        if assigned:
            covered += 1
        else:
            untouched.append(product)
        for group, options in assigned.items():
            for option in options:
                per_group[group][option] += 1
                rows.append(
                    {
                        "productNumber": product["productNumber"],
                        "productId": product["id"],
                        "name": clean_text(product["name"]),
                        "group": group,
                        "option": option,
                    }
                )

    with open(args.assignments, "w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(
            fh, fieldnames=["productNumber", "productId", "name", "group", "option"]
        )
        writer.writeheader()
        writer.writerows(rows)

    total = len(products)
    print(f"Products:            {total}")
    print(f"With >=1 property:   {covered} ({covered / total * 100:.1f}%)")
    print(f"Without any:         {total - covered}")
    print(f"Assignments written: {len(rows)} -> {args.assignments}\n")
    print(f"{'GROUP':22s} {'PRODUCTS':>9s} {'OPTIONS':>8s}")
    group_products = defaultdict(set)
    for row in rows:
        group_products[row["group"]].add(row["productNumber"])
    for group in [g["group"] for g in groups]:
        if group in per_group:
            print(
                f"{group:22s} {len(group_products[group]):9d} {len(per_group[group]):8d}"
            )
    if args.verbose:
        for group, counter in per_group.items():
            print(f"\n--- {group} ({len(counter)} options) ---")
            for option, count in counter.most_common():
                print(f"  {count:5d}  {option}")
    if untouched:
        print(f"\n--- {len(untouched)} products with no property (first 40) ---")
        for product in untouched[:40]:
            print(f"  {product['productNumber']:>14s}  {clean_text(product['name'])}")


# ---------------------------------------------------------------- apply

def deterministic_id(*parts):
    key = "|".join(parts)
    return hashlib.md5(("sivanka-props:" + key).encode("utf-8")).hexdigest()


def chunked(seq, size):
    for i in range(0, len(seq), size):
        yield seq[i : i + size]


def fetch_existing_groups(api):
    """{group_name_lower: {'id': .., 'options': {option_name_lower: id}}}"""
    existing = {}
    page = 1
    while True:
        res = api.request(
            "POST",
            "/api/search/property-group",
            {
                "page": page,
                "limit": 100,
                "associations": {"options": {"limit": 500}},
            },
        )
        rows = res.get("data", [])
        for row in rows:
            options = {}
            for option in row.get("options") or []:
                name = (option.get("translated") or {}).get("name") or option.get("name")
                if name:
                    options[name.strip().lower()] = option["id"]
            name = (row.get("translated") or {}).get("name") or row.get("name")
            if name:
                existing[name.strip().lower()] = {"id": row["id"], "options": options}
        if len(rows) < 100:
            break
        page += 1
    return existing


def cmd_apply(args):
    groups_cfg = {g["group"]: g for g in load_rules(args.rules)}
    with open(args.assignments, encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    if not rows:
        sys.exit("No assignments found. Run 'extract' first.")

    api = None if args.dry_run else api_from_args(args)
    existing = fetch_existing_groups(api) if api else {}
    reused = []

    def group_id(group):
        hit = existing.get(group.strip().lower())
        if hit:
            reused.append(group)
            return hit["id"]
        return deterministic_id(group)

    def option_id(group, option):
        hit = existing.get(group.strip().lower())
        if hit:
            match = hit["options"].get(option.strip().lower())
            if match:
                return match
        return deterministic_id(group, option)

    group_options = defaultdict(set)
    product_options = defaultdict(set)
    for row in rows:
        group_options[row["group"]].add(row["option"])
        product_options[row["productId"]].add(option_id(row["group"], row["option"]))

    group_payload = []
    for group, options in group_options.items():
        cfg = groups_cfg.get(group, {})
        hex_codes = cfg.get("hexCodes") or {}
        option_payload = []
        for option in sorted(options):
            entry = {"id": option_id(group, option), "name": option}
            if option in hex_codes:
                entry["colorHexCode"] = hex_codes[option]
            option_payload.append(entry)
        group_payload.append(
            {
                "id": group_id(group),
                "name": group,
                "displayType": cfg.get("displayType", "text"),
                "sortingType": "alphanumeric",
                "filterable": True,
                "options": option_payload,
            }
        )
    if reused:
        print(f"Reusing {len(set(reused))} existing property group(s): {', '.join(sorted(set(reused)))}")

    product_payload = [
        {
            "id": product_id,
            "properties": [{"id": option_id} for option_id in sorted(option_ids)],
        }
        for product_id, option_ids in product_options.items()
    ]

    print(f"Property groups:   {len(group_payload)}")
    print(f"Property options:  {sum(len(g['options']) for g in group_payload)}")
    print(f"Products to link:  {len(product_payload)}")
    print(f"Option links:      {sum(len(p['properties']) for p in product_payload)}")

    if args.dry_run:
        print("\n[dry-run] nothing written. Sample group payload:")
        print(json.dumps(group_payload[:1], ensure_ascii=False, indent=2)[:1200])
        print("\n[dry-run] sample product payload:")
        print(json.dumps(product_payload[:3], ensure_ascii=False, indent=2))
        return

    headers = {"indexing-behavior": "use-queue-indexing", "single-operation": "0"}

    print("\nUpserting property groups + options ...")
    api.request(
        "POST",
        "/api/_action/sync",
        {
            "write-property-groups": {
                "entity": "property_group",
                "action": "upsert",
                "payload": group_payload,
            }
        },
        headers,
    )
    print("  done")

    print("Linking properties to products ...")
    done = 0
    for chunk in chunked(product_payload, args.batch):
        api.request(
            "POST",
            "/api/_action/sync",
            {
                "write-product-properties": {
                    "entity": "product",
                    "action": "upsert",
                    "payload": chunk,
                }
            },
            headers,
        )
        done += len(chunk)
        print(f"  {done}/{len(product_payload)}")
    print("\nDone. Run 'bin/console dal:refresh:index' if the storefront filters look stale.")


# ---------------------------------------------------------------- clear


def cmd_clear(args):
    """Undo what this tool wrote.

    Groups this tool created are deleted outright. Groups that already existed
    (matched by name) keep their own options - only the options this tool added
    to them are removed.
    """
    groups_cfg = load_rules(args.rules)
    with open(args.assignments, encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    api = api_from_args(args)
    existing = fetch_existing_groups(api)

    our_options = defaultdict(set)
    for row in rows:
        our_options[row["group"]].add(row["option"])

    delete_groups, delete_options = [], []
    for cfg in groups_cfg:
        group = cfg["group"]
        own_id = deterministic_id(group)
        hit = existing.get(group.strip().lower())
        if hit and hit["id"] != own_id:
            for option in our_options.get(group, ()):
                oid = deterministic_id(group, option)
                if oid in hit["options"].values():
                    delete_options.append((group, option, oid))
        elif hit:
            delete_groups.append((group, own_id))

    print(f"Property groups to delete: {len(delete_groups)}")
    for group, gid in delete_groups:
        print(f"  {gid}  {group}")
    print(f"Options to delete from pre-existing groups: {len(delete_options)}")
    for group, option, oid in delete_options[:20]:
        print(f"  {oid}  {group} / {option}")

    if args.dry_run:
        print("\n[dry-run] nothing deleted.")
        return

    operations = {}
    if delete_groups:
        operations["delete-groups"] = {
            "entity": "property_group",
            "action": "delete",
            "payload": [{"id": gid} for _, gid in delete_groups],
        }
    if delete_options:
        operations["delete-options"] = {
            "entity": "property_group_option",
            "action": "delete",
            "payload": [{"id": oid} for _, _, oid in delete_options],
        }
    if not operations:
        print("Nothing to delete.")
        return
    api.request("POST", "/api/_action/sync", operations, {"single-operation": "0"})
    print("Deleted.")


# ---------------------------------------------------------------- cli


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--url", help="Shop base URL (default: $SW_API_URL)")
    parser.add_argument("--id", help="Integration access key id (default: $SW_API_ID)")
    parser.add_argument("--secret", help="Integration secret (default: $SW_API_SECRET)")
    parser.add_argument("--rules", default=DEFAULT_RULES)
    parser.add_argument("--products", default=DEFAULT_PRODUCTS)
    parser.add_argument("--assignments", default=DEFAULT_ASSIGNMENTS)
    sub = parser.add_subparsers(dest="command", required=True)

    p_fetch = sub.add_parser("fetch", help="download all products via Admin API")
    p_fetch.add_argument("--batch", type=int, default=500)
    p_fetch.set_defaults(func=cmd_fetch)

    p_extract = sub.add_parser("extract", help="derive properties from names/descriptions")
    p_extract.add_argument("-v", "--verbose", action="store_true", help="list every option with counts")
    p_extract.set_defaults(func=cmd_extract)

    p_apply = sub.add_parser("apply", help="write groups, options and product links")
    p_apply.add_argument("--dry-run", action="store_true")
    p_apply.add_argument("--batch", type=int, default=200)
    p_apply.set_defaults(func=cmd_apply)

    p_clear = sub.add_parser("clear", help="undo: delete the groups/options this tool created")
    p_clear.add_argument("--dry-run", action="store_true")
    p_clear.set_defaults(func=cmd_clear)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
