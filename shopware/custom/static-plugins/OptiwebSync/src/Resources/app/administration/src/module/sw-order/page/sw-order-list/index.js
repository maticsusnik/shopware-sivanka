/* global Shopware */

const {Component} = Shopware;
import template from './sw-order-list.html.twig';


Component.override("sw-order-list", {
    template,
    methods: {
        getOrderColumns() {
            const defaultColumns = this.$super("getOrderColumns");
            return [...defaultColumns, {
                property: 'customFields.optiwebOrderStatus',
                dataIndex: 'customFields.optiwebOrderStatus',
                label: 'ERP Status',
                allowResize: true,
            }]
        },
        getStatus(item){
            try{
                return item.customFields.optiwebOrderStatus;
            }
            catch(e){
                return "nodata";
            }
        }
    }
})
