<?php
use PHPUnit\Framework\TestCase;

final class DbFeedbackTest extends TestCase
{
    private function sample(array $overrides = []): array
    {
        return array_merge([
            'type'       => 'bug',
            'message'    => 'Class shows wrong',
            'name'       => 'Pat',
            'email'      => 'pat@example.com',
            'user_id'    => 3,
            'page_url'   => 'https://x.test/car-classing.html',
            'user_agent' => 'UA',
            'viewport'   => '1200x800',
            'calc_inputs'=> '{"competitionWeight":"2800"}',
            'ip_hash'    => 'hash1',
            'created_at' => '2026-09-23 10:00:00',
        ], $overrides);
    }

    public function testInsertAndGet(): void
    {
        $pdo = make_temp_pdo();
        $id = db_insert_feedback($pdo, $this->sample());

        $row = db_get_feedback($pdo, $id);
        $this->assertSame('bug', $row['type']);
        $this->assertSame('Class shows wrong', $row['message']);
        $this->assertSame('Pat', $row['name']);
        $this->assertSame('new', $row['status']);
        $this->assertNull($row['github_issue_number']);
        $this->assertNull($row['github_error']);
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->assertNull(db_get_feedback(make_temp_pdo(), 999));
    }

    public function testGetAllNewestFirst(): void
    {
        $pdo = make_temp_pdo();
        $old = db_insert_feedback($pdo, $this->sample(['created_at' => '2026-09-01 10:00:00']));
        $new = db_insert_feedback($pdo, $this->sample(['created_at' => '2026-09-23 10:00:00']));

        $ids = array_column(db_get_all_feedback($pdo), 'id');
        $this->assertSame([$new, $old], array_map('intval', $ids));
    }

    public function testUpdateStatus(): void
    {
        $pdo = make_temp_pdo();
        $id = db_insert_feedback($pdo, $this->sample());
        db_update_feedback_status($pdo, $id, 'triaged');
        $this->assertSame('triaged', db_get_feedback($pdo, $id)['status']);
    }

    public function testSetGithubClearsError(): void
    {
        $pdo = make_temp_pdo();
        $id = db_insert_feedback($pdo, $this->sample());
        db_set_feedback_github_error($pdo, $id, 'HTTP 500');
        $this->assertSame('HTTP 500', db_get_feedback($pdo, $id)['github_error']);

        db_set_feedback_github($pdo, $id, 42, 'https://github.com/o/r/issues/42');
        $row = db_get_feedback($pdo, $id);
        $this->assertSame(42, (int)$row['github_issue_number']);
        $this->assertSame('https://github.com/o/r/issues/42', $row['github_issue_url']);
        $this->assertNull($row['github_error']);
    }

    public function testCountRecentByIpHash(): void
    {
        $pdo = make_temp_pdo();
        db_insert_feedback($pdo, $this->sample(['ip_hash' => 'a', 'created_at' => '2026-09-23 09:00:00']));
        db_insert_feedback($pdo, $this->sample(['ip_hash' => 'a', 'created_at' => '2026-09-23 10:30:00']));
        db_insert_feedback($pdo, $this->sample(['ip_hash' => 'b', 'created_at' => '2026-09-23 10:30:00']));

        $this->assertSame(1, db_count_recent_feedback_by_ip_hash($pdo, 'a', '2026-09-23 10:00:00'));
        $this->assertSame(2, db_count_recent_feedback_by_ip_hash($pdo, 'a', '2026-09-23 08:00:00'));
        $this->assertSame(0, db_count_recent_feedback_by_ip_hash($pdo, 'zzz', '2026-09-23 08:00:00'));
    }
}
