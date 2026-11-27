<?php
declare(strict_types=1);

return [
    'table:promoters',
    'column:promoters.id',
    'column-type:promoters.promoter_key=varchar(32)',
    'column-not-null-default:promoters.active=1',
    'unique-index:promoters.PRIMARY=id',
    'unique-index:promoters.uq_promoter_key=promoter_key',
    'check-constraint:promoters.ck_promoter_active',
    'table:promoter_codes',
    'column:promoter_codes.id',
    'column:promoter_codes.promoter_id',
    'column-type:promoter_codes.code=varchar(32)',
    'column-not-null-default:promoter_codes.active=1',
    'unique-index:promoter_codes.PRIMARY=id',
    'unique-index:promoter_codes.uq_promoter_code=code',
    'index:promoter_codes.idx_promoter_codes_owner',
    'fk-reference:promoter_codes.fk_promoter_code_owner=promoter_id:promoters.id',
    'check-constraint:promoter_codes.ck_promoter_code_active',
    'column-unsigned-bigint-nullable:ticket_orders.promoter_code_id',
    'index:ticket_orders.idx_order_promoter_code',
    'fk-reference:ticket_orders.fk_order_promoter_code=promoter_code_id:promoter_codes.id',
];
