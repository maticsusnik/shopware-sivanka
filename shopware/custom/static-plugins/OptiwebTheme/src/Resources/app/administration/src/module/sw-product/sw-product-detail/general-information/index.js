const {Component} = Shopware;
import template from "./template.html.twig";
import {customFieldSetAndGet} from "../../../../helper";

Component.override('sw-product-basic-form', {
    template,
    computed:{
        shortDescription: customFieldSetAndGet("product", "shortDescription"),
    }
});
