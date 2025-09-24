<?php declare(strict_types=1);

namespace OptiwebSnippets;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;

class OptiwebSnippets extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $snippetSetRepository = $this->container->get("snippet_set.repository");
        $snippetFileName = "BASE sl-SI | Slovenščina";

        # Check if snippet already exists
        $snippetSetCriteria = new Criteria();
        $snippetSetCriteria->addFilter(new EqualsFilter("name", $snippetFileName));
        $snippetSetResultSlo = $snippetSetRepository->search($snippetSetCriteria, $installContext->getContext());

        # Insert snippet set if empty result
        if ($snippetSetResultSlo->getTotal() === 0) {
            $slovenianSnippet = [
                "name" => $snippetFileName,
                "baseFile" => "messages.sl-SI",
                "iso" => "sl-SI"
            ];
            $snippetSetRepository->create([$slovenianSnippet], $installContext->getContext());
        }

    }

}
