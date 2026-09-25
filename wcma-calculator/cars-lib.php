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
