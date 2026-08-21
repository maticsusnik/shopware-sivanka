<?php declare(strict_types=1);

namespace OptiwebSync\Helper;

class GlobalVariables
{
    public const DEFAULT_MEDIA_FOLDER = 'Product Media';
    public const DEFAULT_MEDIA_FOLDER_CATEGORY = 'Category Media';
    public const BATCH_SIZE = 500;
    public const DEFAULT_LANGUAGE = 'sl-SI';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIALLY_SENT = 'partially_sent';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARTIALLY_SHIPPED = 'partially_shipped';
    public const STATUS_CANCELLED = 'cancelled';

    public const CUSTOM_FIELD_SET = 'optiweb_sync_order';
    public const CUSTOM_FIELD_OPTIWEB_STATUS = 'optiwebOrderStatus';
    public const CUSTOM_FIELD_OPTIWEB_STATUS_CODE = 'optiwebOrderStatusCode';
    public const CUSTOM_FIELD_OPTIWEB_ERROR = 'optiwebOrderError';
    public const CUSTOM_FIELD_OPTIWEB_KEY = 'optiwebOrderKey';
}
