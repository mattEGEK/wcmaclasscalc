<?php
// wcma-calculator/tests/DbInspectionPhotosTest.php
use PHPUnit\Framework\TestCase;

final class DbInspectionPhotosTest extends TestCase
{
    private function photo(array $overrides = []): array {
        return array_merge([
            'subject_type' => 'tech_sheet', 'subject_id' => 7, 'requirement_key' => 'front_34',
            'requirement_version' => 1, 'file_path' => 'uploads/inspection/tech_sheet/7/front_34.jpg',
            'typed_value' => null,
        ], $overrides);
    }

    public function testInsertThenGet(): void
    {
        $pdo = make_temp_pdo();
        $this->assertNull(db_upsert_inspection_photo($pdo, $this->photo()));

        $photos = db_get_inspection_photos($pdo, 'tech_sheet', 7);
        $this->assertSame(['front_34'], array_keys($photos));
        $row = $photos['front_34'];
        $this->assertSame('pending', $row['review_status']);
        $this->assertSame(1, (int)$row['applies']);
        $this->assertSame(1, (int)$row['requirement_version']);

        $byId = db_get_inspection_photo($pdo, (int)$row['id']);
        $this->assertSame('front_34', $byId['requirement_key']);
        $this->assertNull(db_get_inspection_photo($pdo, 99999));
    }

    public function testReplacingReturnsPreviousPathAndResetsReview(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo(['file_path' => 'a.jpg', 'typed_value' => '{"date":"01/2025"}']));
        $id = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['front_34']['id'];
        $pdo->prepare("UPDATE inspection_photos SET review_status = 'retake', reviewer_note = 'blurry' WHERE id = :id")->execute([':id' => $id]);

        $previous = db_upsert_inspection_photo($pdo, $this->photo(['file_path' => 'b.png', 'typed_value' => null]));
        $this->assertSame('a.jpg', $previous);

        $rows = db_get_inspection_photos($pdo, 'tech_sheet', 7);
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int)$rows['front_34']['id']);
        $this->assertSame('b.png', $rows['front_34']['file_path']);
        $this->assertSame('pending', $rows['front_34']['review_status']);
        $this->assertNull($rows['front_34']['reviewer_note']);
        $this->assertNull($rows['front_34']['typed_value']);
    }

    public function testPhotosAreScopedBySubject(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 7]));
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 8]));
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 7, 'requirement_key' => 'rear_34']));

        $this->assertCount(2, db_get_inspection_photos($pdo, 'tech_sheet', 7));
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', 8));
        $this->assertSame([], db_get_inspection_photos($pdo, 'gear_record', 7));
    }

    public function testDelete(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo());
        $id = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['front_34']['id'];

        db_delete_inspection_photo($pdo, $id);
        $this->assertNull(db_get_inspection_photo($pdo, $id));
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }
}
