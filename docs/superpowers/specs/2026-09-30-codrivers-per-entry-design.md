# Co-drivers per Car and Entry: Design Spec

Date: 2026-09-30. Status: design approved in brainstorming. The written spec is waiting for review.

## Problem

A co-driver belongs only to the owner's account (`drivers.owner_user_id`). Nothing links a driver to
a car or to an event entry. As a result:

- **Every sheet offers every driver.** Every tech sheet's driver picker offers every driver the
  owner has ever added (`db_get_user_drivers`, tech-sheets.php).
- **Race and ice ask for gear from everyone.** Readiness for race and ice entries asks for gear
  from every driver on the profile:
  - race: `readiness-lib.php`, the `array_keys($in['drivers'])` merge;
  - ice: `readinessIceCarItems`.

  This came from commit 1eac61d, "gear reminders for every profile driver". Reminder emails
  (`reminderDigests(buildReadiness(...))`) repeat it.
- **TA/Drift already scopes its drivers.** It only asks for gear from drivers named on the relevant
  sheet.

Endurance and race co-drivers are really specific to one car, and only drive at some events.

## Decisions made while brainstorming

| Question | Decision |
|---|---|
| Scope | A co-driver belongs to a **car for the season**, and only shows up at events where the owner says they're driving. |
| The owner | Is ticked by default on every entry and can untick themself (someone else drives their car). |
| How a co-driver joins a car | From the car page ("Co-drivers" section), **and** automatically when named on that car's tech sheet. |
| Default when a car is added to an event | Only the owner is ticked. Co-drivers start unticked. |
| Standard race sheet with more than one driver ticked | The sheet form suggests switching to the endurance sheet type. |
| Build approach | Two new tables, `car_drivers` and `entry_drivers`. Not JSON columns, and not derived from sheets alone. |

## 1. Data model

No existing columns change. The migrations run in `db_init()` and are safe to run twice.

### `car_drivers`: each car's co-driver list

```
id, car_id INTEGER NOT NULL, driver_id INTEGER NOT NULL, created_at DATETIME NOT NULL,
UNIQUE (car_id, driver_id)
```

- The owner's own driver (`user_id = owner_user_id`) is never stored here. The owner is implied on
  every car.
- A driver can be on several cars.
- Removing a row never deletes the driver.

### `entry_drivers`: who's driving an entry

```
id, entry_id INTEGER NOT NULL (event_plans.id), driver_id INTEGER NOT NULL, created_at DATETIME NOT NULL,
UNIQUE (entry_id, driver_id)
```

- This **does** include the owner's own driver when the owner is driving.
- An entry must always have at least one driver.
- Untagging an entry deletes its `entry_drivers` rows.

### One-time backfill

This runs in `db_init()`, guarded so it only fills tables that are still empty.

- **`car_drivers`:** for each car, every non-owner driver named on any of that car's tech sheets
  (`tech_sheets.driver_id` plus `tech_sheet_drivers`).
- **`entry_drivers`:** for each existing entry:
  - the owner's own driver;
  - plus every driver named on that car's sheet for that event, if there is one.

  If that event's sheet names someone other than the owner as driver 1, the owner row still goes in,
  so nobody's current to-dos disappear on deploy. The owner can untick themself.

### Functions (`db.php`)

- `db_get_car_drivers(PDO, int $carId): array`: the co-driver rows, sorted by name.
- `db_add_car_driver(PDO, int $userId, int $carId, int $driverId): bool`: checks that the car and
  the driver both belong to `$userId`, and ignores the owner's own driver.
- `db_remove_car_driver(PDO, int $userId, int $carId, int $driverId): bool`
- `db_get_entry_driver_ids(PDO, int $entryId): int[]`
- `db_set_entry_drivers(PDO, int $userId, int $entryId, int[] $driverIds): void`: every ID must be
  the owner's own driver or on the car's list. The list must not be empty.
- `db_add_entry_driver(PDO, int $entryId, int $driverId): void`: adds one driver, doing nothing if
  already there. Used when a sheet names a driver.

## 2. Car page: Co-drivers

This is a new section on the Garage car page (`garage.php` / `garage-page.php`), under the car
details.

- It lists the car's co-drivers, each with a **Remove** button (POST, CSRF).
- **Add a co-driver:**
  - a picker of the owner's other drivers who aren't on this car yet, plus a "New name" field;
  - it uses the existing `db_find_or_create_driver`;
  - a POST `add-co-driver` action in `garage.php`.
- Copy:
  - Section heading: "Co-drivers".
  - Intro: "People who share this car. Tick who's driving at each event."
  - Empty state: "No co-drivers yet."
- **Removing a co-driver from a car** also unticks them from that car's **upcoming** entries. Past
  entries keep their history.

## 3. Entry: Who's driving?

Every place a car is added to an event, or its formats are changed, gains a "Who's driving?" group:

- Home's "I'm going" form and the Change form on the event card (`homeRenderTagForm`,
  `homeRenderEntryFormatsHtml`);
- the car page's Events section (`garageRenderEntryFormatsHtml` and its "I'm going" form).

It applies to **every** entry, ice included. Ice events show the driver group without the format
boxes.

- **Checkboxes:** the owner ("You"), then each co-driver on the car's list.
- **When a car is first added to an event:** the owner is ticked and co-drivers are unticked.
- **Validation:** at least one box must be ticked. If none is, the error reads "Tick at least one
  driver." and nothing is saved.
- **Saving:** `eventsTagCar` / `eventsSetFormats` gain an optional `?array $driverIds`. When it's
  null (older callers), a new entry gets only the owner and an existing entry is left alone. Posted
  IDs that aren't the owner's and aren't on the car's list are rejected.
- **Showing:** the event card and the car page list the ticked names, for example
  "Driving: You · Sam Lee".

## 4. Tech sheets

This covers every sheet type: standard, endurance, ice and TA/Drift (`tech-sheets.php`, all the form
view models that call `db_get_user_drivers`).

- **The driver pickers only offer** the owner's own driver plus this car's co-drivers
  (`db_get_car_drivers`). Typing a new name still works. The new driver is created and added to the
  car's list.
- **Pre-fill for a new sheet on an event with an entry:**
  - driver 1 is the owner if ticked, otherwise the first ticked co-driver;
  - the other ticked drivers pre-fill the additional-driver rows (endurance and TA/Drift).
- **When a sheet is submitted or updated,** every driver it names:
  - is added to the car's list, if not already there and not the owner;
  - is ticked on that event's entry (`db_add_entry_driver`).

  The sheet wins over the entry.
- **Standard race sheet with more than one driver ticked:** the form shows a notice, "More than one
  driver is ticked for this event. Use the endurance sheet so everyone is on it.", with a link that
  switches the form to the endurance type. There is no hard block.

## 5. Readiness and reminders (`readiness-lib.php`)

One rule for race, ice and TA/Drift entries: the drivers who get a gear to-do for an entry are that
entry's `entry_drivers`, plus anyone named on that event's own sheet for the car (who should
already be ticked, per §4).

- `loadReadinessInputs()` also loads `entryDrivers` (entry id → driver ids), and each
  `readinessEntry()` row carries `driverIds`.
- **Race path:** drop the `array_keys($in['drivers'])` merge and the unconditional
  `selfDriverId`. Use the entry's drivers plus the event sheet's drivers.
- **Ice path (`readinessIceCarItems`):** the same change.
- **TA/Drift path (`readinessTaDriftCarItems`):** use the entry's drivers plus the event sheet's
  drivers. The fallback of "the latest accepted club sheet's drivers, then the owner" applies only
  to legacy entries with no `entry_drivers` rows.
- **Entries with no `entry_drivers` rows** fall back to the owner's own driver. There are none after
  the backfill, but this is defensive.
- **Reminders** change with readiness automatically.
- **The Home "Drivers" card and the Drivers page** still list everyone the owner manages, with their
  gear status. They're the address book. They no longer create per-event gear to-dos for
  co-drivers who aren't driving anything.

## 6. Unchanged

- Gear records: one per driver, discipline and season.
- The `drivers` table and the Drivers page (add, rename, delete).
- Inspector screens.
- Entry formats and tech tiers (the TA/Drift spec).

## 7. Testing (PHPUnit, `wcma-calculator/tests/`)

- `car_drivers`:
  - adding and removing;
  - ownership checks;
  - the owner is never stored;
  - no duplicates.
- `entry_drivers`:
  - the default is owner-only;
  - a posted list is validated (owner or on the car's list, and not empty);
  - untagging clears it;
  - removing a co-driver from a car unticks them from upcoming entries only.
- Backfill:
  - car lists come from past sheets;
  - each entry gets the owner plus the event sheet's drivers;
  - running it twice changes nothing.
- Readiness (race, ice and TA/Drift):
  - a co-driver who isn't ticked gets no gear to-do;
  - ticking brings it back;
  - unticking the owner removes the owner's gear to-do for that entry;
  - a sheet naming someone makes them count.
- Reminders: a co-driver who isn't ticked isn't in the digest.
- Sheet forms:
  - pickers offer only the owner and the car's co-drivers;
  - pre-fill follows the ticked drivers;
  - submitting a sheet adds its drivers to the car's list and ticks them on the entry;
  - a standard sheet with more than one driver ticked shows the endurance notice.
- Phone audit: the car page's Co-drivers section and the "Who's driving?" group on Home.

## Out of scope

- Co-drivers with their own hub logins, or seeing the entry from their own account.
- Per-event driver changes after the event.
- Suggesting co-drivers from other owners.
