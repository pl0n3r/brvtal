<?php
declare(strict_types=1);

/** Copy only. The transactional adapter stays disabled until operator gates pass. */
return [
    'verify_account' => [
        'subject' => 'BRVTAL | Confirma tu correo electrónico',
        'title' => 'Confirma tu correo',
        'message' => 'Usa este enlace para verificar tu cuenta. Si no lo solicitaste, ignora este mensaje.',
        'cta' => 'Confirmar correo',
    ],
    'reset_password' => [
        'subject' => 'BRVTAL | Restablece tu contraseña',
        'title' => 'Restablece tu contraseña',
        'message' => 'El enlace es de un solo uso y vence según la seguridad de tu cuenta. Si no lo solicitaste, ignóralo.',
        'cta' => 'Restablecer contraseña',
    ],
    'order_pending' => [
        'subject' => 'BRVTAL | Pedido pendiente de pago',
        'title' => 'Tu pedido está pendiente',
        'message' => 'Aún no se confirmó el pago. Este mensaje no es una boleta ni autoriza el ingreso.',
        'cta' => 'Consultar pedido',
    ],
    'order_rejected' => [
        'subject' => 'BRVTAL | No pudimos confirmar tu pedido',
        'title' => 'El pago no fue aprobado',
        'message' => 'No se han emitido boletas para este intento. Consulta el estado de tu pedido.',
        'cta' => 'Ver estado del pedido',
    ],
    'payment_approved' => [
        'subject' => 'BRVTAL | Pago aprobado: tus boletas',
        'title' => 'Tu pago fue aprobado',
        'message' => 'Aquí están tus boletas. Cada enlace muestra el código QR que se escanea en la puerta; guarda este correo y no lo reenvíes.',
        'cta' => 'Abrir Mi Cuenta',
    ],
    'reminder' => [
        'subject' => 'BRVTAL | Recordatorio de tu evento',
        'title' => 'Nos vemos pronto',
        'message' => 'Consulta la información actualizada del evento y tus boletas en Mi Cuenta antes de asistir.',
        'cta' => 'Ver mis boletas',
    ],
    'refund' => [
        'subject' => 'BRVTAL | Actualización de devolución',
        'title' => 'Actualización de tu devolución',
        'message' => 'La devolución fue registrada en el pedido. La acreditación del dinero depende del proveedor y debe confirmarse por separado.',
        'cta' => 'Consultar estado',
    ],
];
