<?php
declare(strict_types=1);

return [
    'column:ticket_pos_sales.quote_source',
    "column-type:ticket_pos_sales.quote_source=enum('door','stage','base')",
    'column-nullable:ticket_pos_sales.quote_source',
    'column:ticket_pos_sales.quote_stage_code',
    "column-type:ticket_pos_sales.quote_stage_code=enum('early_bird','preventa','general','puerta')",
    'column-nullable:ticket_pos_sales.quote_stage_code',
    'column:ticket_pos_sales.quote_channel',
    "column-type:ticket_pos_sales.quote_channel=enum('door','manual')",
    'column-nullable:ticket_pos_sales.quote_channel',
];
