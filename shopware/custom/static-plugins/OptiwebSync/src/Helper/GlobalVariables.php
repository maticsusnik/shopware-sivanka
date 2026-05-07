<?php

namespace OptiwebSync\Helper;

class GlobalVariables
{
    public const DEFAULT_SALES_CHANNEL = 'Starman';
    public const DEFAULT_MEDIA_FOLDER = 'Product Media';
    public const DEFAULT_MEDIA_FOLDER_CATEGORY = 'Category Media';
    public const VASCO_BATCH_SIZE = 90;
    public const BATCH_SIZE = 500;
    public const DEFAULT_LANGUAGE = 'sl-SI';

    public const PRICE_GROSS_MULTIPLIER = 1.22;

    const STATUS_WAITING = "waiting";
    const STATUS_SENT = "sent";
    const STATUS_PARTIALLY_SENT = "partially_sent";
    const STATUS_DONE = "done";
    const STATUS_ERROR = "error";
    const STATUS_PROCESSING = "processing";
    const STATUS_PARTIALLY_SHIPPED = "partially_shipped";
    const STATUS_CANCELLED = "cancelled";


    const CUSTOM_FIELD_OPTIWEB_STATUS = "optiwebOrderStatus";
    const CUSTOM_FIELD_OPTIWEB_STATUS_CODE = "optiwebOrderStatusCode";
    const CUSTOM_FIELD_OPTIWEB_ERROR = "optiwebOrderError";
    const CUSTOM_FIELD_OPTIWEB_KEY = "optiwebOrderKey";



}
