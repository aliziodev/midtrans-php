<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\SnapBi;

final class SnapBiPath
{
    public const ACCESS_TOKEN = '/v1.0/access-token/b2b';

    public const DEBIT_CREATE = '/v1.0/debit/payment-host-to-host';

    public const DEBIT_STATUS = '/v1.0/debit/status';

    public const DEBIT_CANCEL = '/v1.0/debit/cancel';

    public const DEBIT_REFUND = '/v1.0/debit/refund';

    public const VA_CREATE = '/v1.0/transfer-va/create-va';

    public const VA_STATUS = '/v1.0/transfer-va/status';

    public const VA_CANCEL = '/v1.0/transfer-va/delete-va';

    public const QRIS_CREATE = '/v1.0/qr/qr-mpm-generate';

    public const QRIS_STATUS = '/v1.0/qr/qr-mpm-query';

    public const QRIS_CANCEL = '/v1.0/qr/qr-mpm-cancel';

    public const QRIS_REFUND = '/v1.0/qr/qr-mpm-refund';
}
