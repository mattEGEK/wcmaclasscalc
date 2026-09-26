<?php
// wcma-calculator/cars-lib.php
//
// Car and class-declaration helpers. Callers must have loaded db.php.

/**
 * The car a class declaration is for. $post['car_id'] is one of the user's active car ids, or
 * 'new' to create one from car_number/year/make/model. An existing car takes the year/make/model
 * the competitor just declared, so the car record matches its newest declaration.
 *
 * @return array{ok: bool, error: ?string, car_id: ?int}
 */
function carsResolveForDeclaration(PDO $pdo, int $userId, array $post): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'car_id' => null];
    $field = trim((string)($post['car_id'] ?? ''));
    $details = [
        'year' => trim((string)($post['year'] ?? '')),
        'make' => trim((string)($post['make'] ?? '')),
        'model' => trim((string)($post['model'] ?? '')),
    ];

    if ($field === '') return $fail('Choose which car this class declaration is for.');

    if ($field !== 'new') {
        $car = ctype_digit($field) ? db_get_user_car($pdo, $userId, (int)$field) : null;
        if ($car === null || $car['archived_at'] !== null) return $fail('Choose one of your cars, or add a new car.');
        db_update_car($pdo, (int)$car['id'], array_filter($details, fn(string $v): bool => $v !== ''));
        return ['ok' => true, 'error' => null, 'car_id' => (int)$car['id']];
    }

    $number = trim((string)($post['car_number'] ?? ''));
    if ($number === '') return $fail('Enter the car number for your new car.');
    if (mb_strlen($number, 'UTF-8') > 10) return $fail('That car number is too long (10 characters at most).');
    if ($details['make'] === '' || $details['model'] === '') return $fail('Enter the make and model of your new car.');

    $id = db_create_car($pdo, $userId, ['car_number' => $number, 'year' => $details['year'] ?: null] + $details);
    return ['ok' => true, 'error' => null, 'car_id' => $id];
}

const CARS_FIELD_MAX = ['car_number' => 10, 'year' => 4, 'make' => 40, 'model' => 60, 'colour' => 30, 'engine_cc' => 10];
const CARS_FIELD_LABELS = ['car_number' => 'number', 'year' => 'year', 'make' => 'make', 'model' => 'model', 'colour' => 'colour', 'engine_cc' => 'engine size'];

/**
 * The Add a car / Edit details form. Number, make, model and colour are required; year (four
 * digits) and engine size are optional and become null when blank. On failure, data still holds
 * what was typed so the form can be shown again.
 *
 * @return array{ok: bool, error: ?string, data: array<string, ?string>}
 */
function carsValidateDetails(array $post): array {
    $data = [];
    foreach (array_keys(CARS_FIELD_MAX) as $field) {
        $data[$field] = trim((string)preg_replace('/\s+/', ' ', (string)($post[$field] ?? '')));
    }
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'data' => $data];
    foreach (['car_number', 'make', 'model', 'colour'] as $field) {
        if ($data[$field] === '') return $fail("Enter the car's " . CARS_FIELD_LABELS[$field] . '.');
    }
    foreach (CARS_FIELD_MAX as $field => $max) {
        if (mb_strlen($data[$field], 'UTF-8') > $max) return $fail('That ' . CARS_FIELD_LABELS[$field] . " is too long ($max characters at most).");
    }
    if ($data['year'] !== '' && !preg_match('/^(19|20)\d{2}$/', $data['year'])) return $fail('Enter the year as four digits, like 2004.');
    $data['year'] = $data['year'] === '' ? null : $data['year'];
    $data['engine_cc'] = $data['engine_cc'] === '' ? null : $data['engine_cc'];
    return ['ok' => true, 'error' => null, 'data' => $data];
}

function carDisplayName(array $car): string {
    return '#' . $car['car_number'] . ' ' . trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model']);
}

function declarationReviewLabel(string $status): string {
    switch ($status) {
        case 'accepted':      return 'Accepted';
        case 'needs_changes': return 'Needs changes';
        case 'superseded':    return 'Replaced by a newer declaration';
        default:              return 'With an inspector';
    }
}

function declarationReviewBadgeClass(string $status): string {
    if ($status === 'accepted') return 'badge-ok';
    if ($status === 'needs_changes') return 'badge-fail';
    return 'badge-pending';
}

/** What the calculator's car picker needs to know about a car. */
function carsPublicShape(array $car, ?array $declaration): array {
    return [
        'id' => (int)$car['id'], 'car_number' => (string)$car['car_number'], 'year' => $car['year'],
        'make' => (string)$car['make'], 'model' => (string)$car['model'], 'colour' => $car['colour'],
        'label' => carDisplayName($car),
        'current_class' => $declaration['calculated_class'] ?? null,
        'review_status' => $declaration['review_status'] ?? null,
    ];
}

/** A submitted tech sheet's car details become the car's details (the sheet itself keeps its snapshot). */
function carsApplySheetDetails(PDO $pdo, int $carId, string $number, string $colour, ?string $engineCc): void {
    db_update_car($pdo, $carId, ['car_number' => $number, 'colour' => $colour, 'engine_cc' => $engineCc]);
}
