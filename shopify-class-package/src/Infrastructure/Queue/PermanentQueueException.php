<?php

namespace Itmar\ShopifyClassPackage\Infrastructure\Queue;

if (! defined('ABSPATH')) exit;

/**
 * 再試行しても解消しないキュー処理エラー。
 */
final class PermanentQueueException extends \RuntimeException
{
}
