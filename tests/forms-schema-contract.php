<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/form_schema.php';

function formsSchemaExpect(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('FORM CONTRACT FAILED');
    }
}

function formsSchemaReject(callable $operation, string $error): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        formsSchemaExpect($e->getMessage() === $error);
        return;
    }
    throw new RuntimeException('INVALID FORM INPUT ACCEPTED');
}

$base = static fn(string $id, int $order, string $type, bool $required = true): array => [
    'id' => $id, 'order' => $order, 'type' => $type, 'required' => $required, 'label' => 'Prueba sintética',
];
$options = [['value' => 'one', 'label' => 'Opción uno'], ['value' => 'two', 'label' => 'Opción dos']];
$definition = ['form_key' => 'fixture', 'version_number' => 3, 'fields' => [
    $base('nickname', 1, 'text') + ['max_length' => 4, 'help' => 'Ayuda'],
    $base('mail', 2, 'email') + ['max_length' => 254],
    $base('message', 3, 'textarea', false) + ['max_length' => 40],
    $base('choice', 4, 'select') + ['options' => $options],
    $base('radio_choice', 5, 'radio') + ['options' => $options],
    $base('toggle', 6, 'checkbox', false),
    $base('day', 7, 'date'),
    $base('privacy_accept', 8, 'consent') + ['purpose' => 'privacy', 'notice_version' => 'v1.2'],
    $base('marketing_accept', 9, 'consent', false) + ['purpose' => 'marketing', 'notice_version' => 'v1.0'],
]];
$valid = ['nickname' => 'á🌑ñé', 'mail' => 'test@example.invalid', 'choice' => 'one', 'radio_choice' => 'two',
    'toggle' => false, 'day' => '2024-02-29', 'privacy_accept' => true, 'marketing_accept' => false];
$json = json_encode($valid, JSON_THROW_ON_ERROR);
formsSchemaExpect(brvtalFormPayloadValidate($definition, 'fixture', 3, $json) === $valid);
formsSchemaExpect(brvtalFormPayloadValidate($definition, 'fixture', 3, json_encode($valid + ['message' => 'literal "key": colon'], JSON_THROW_ON_ERROR))['message'] === 'literal "key": colon');
$historical = $definition;
$historical['version_number'] = 2;
$historical['fields'][0]['max_length'] = 3;
formsSchemaReject(static fn() => brvtalFormPayloadValidate($historical, 'fixture', 2, $json), 'FORM_PAYLOAD_INVALID');
formsSchemaExpect($definition['fields'][0]['max_length'] === 4);
$ordinary = $definition;
$ordinary['fields'][0]['id'] = 'display_name';
$ordinaryPayload = $valid;
$ordinaryPayload['display_name'] = $ordinaryPayload['nickname'];
unset($ordinaryPayload['nickname']);
formsSchemaExpect(brvtalFormPayloadValidate($ordinary, 'fixture', 3,
    json_encode($ordinaryPayload, JSON_THROW_ON_ERROR))['display_name'] === 'á🌑ñé');

foreach ([['id' => 'password'], ['id' => 'csrf_token'], ['id' => 'form_key'], ['id' => 'mail'],
    ['id' => 'api_key'], ['id' => 'private_key'], ['id' => 'api__key'], ['id' => 'credit_card'],
    ['order' => 2], ['order' => '1'], ['type' => 'password'], ['type' => 'file'], ['type' => 'payment'],
    ['type' => 'url'], ['required' => 1], ['label' => '<script>'], ['help' => 'https://example.invalid'],
    ['label' => "\xFF"], ['max_length' => 0], ['max_length' => 4097], ['max_length' => '4'],
    ['conditions' => []], ['options' => $options], ['help' => null], ['label' => '  ']] as $patch) {
    $bad = $definition;
    $bad['fields'][0] = array_replace($bad['fields'][0], $patch);
    formsSchemaReject(static fn() => brvtalFormSchemaValidate($bad), 'FORM_SCHEMA_INVALID');
}
foreach ([[], [$options[0], $options[0]], array_fill(0, 33, $options[0]), [['value' => 'one', 'label' => '<b>']],
    [['value' => ['one'], 'label' => 'One']], [['value' => 'one', 'label' => 'One', 'url' => 'x']]] as $badOptions) {
    $bad = $definition;
    $bad['fields'][3]['options'] = $badOptions;
    formsSchemaReject(static fn() => brvtalFormSchemaValidate($bad), 'FORM_SCHEMA_INVALID');
}
foreach ([['purpose' => 'privacy'], ['required' => true], ['notice_version' => ''], ['notice_version' => []]] as $patch) {
    $bad = $definition;
    $bad['fields'][8] = array_replace($bad['fields'][8], $patch);
    formsSchemaReject(static fn() => brvtalFormSchemaValidate($bad), 'FORM_SCHEMA_INVALID');
}
foreach ([['nickname' => 'á🌑ñéx'], ['nickname' => '  '], ['nickname' => []], ['nickname' => null], ['nickname' => 123],
    ['choice' => 'other'], ['radio_choice' => true], ['toggle' => 'false'], ['toggle' => 0], ['day' => '2023-02-29'],
    ['day' => '2024-2-29'], ['mail' => 'invalid'], ['privacy_accept' => false], ['privacy_accept' => 'true'],
    ['marketing_accept' => 1], ['extra' => 'unexpected'], ['message' => ['deep' => ['x']]]] as $patch) {
    $badJson = json_encode(array_replace($valid, $patch), JSON_THROW_ON_ERROR);
    formsSchemaReject(static fn() => brvtalFormPayloadValidate($definition, 'fixture', 3, $badJson), 'FORM_PAYLOAD_INVALID');
}
foreach (['[]', 'null', '{}', '{', "{\"nickname\":\"\xFF\"}", str_repeat(' ', 65537),
    substr($json, 0, -1) . ',"nickname":"x"}', substr($json, 0, -1) . ',"nick\\u006eame":"x"}'] as $badJson) {
    formsSchemaReject(static fn() => brvtalFormPayloadValidate($definition, 'fixture', 3, $badJson), 'FORM_PAYLOAD_INVALID');
}
formsSchemaReject(static fn() => brvtalFormPayloadValidate($definition, 'fixture', '3', $json), 'FORM_PAYLOAD_INVALID');
formsSchemaReject(static fn() => brvtalFormPayloadValidate($definition, 'other', 3, $json), 'FORM_PAYLOAD_INVALID');
$bad = $definition;
$bad['fields'] = array_fill(0, 33, $definition['fields'][0]);
formsSchemaReject(static fn() => brvtalFormSchemaValidate($bad), 'FORM_SCHEMA_INVALID');
echo "BRVTAL forms schema contract passed.\n";
