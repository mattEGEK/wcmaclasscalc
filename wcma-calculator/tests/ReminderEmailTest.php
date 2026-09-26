<?php
// wcma-calculator/tests/ReminderEmailTest.php
require_once __DIR__ . '/../reminder-email.php';

use PHPUnit\Framework\TestCase;

final class ReminderEmailTest extends TestCase
{
    private const BASE = 'https://x.test/classing/';
    private const UNSUB = 'https://x.test/classing/unsubscribe.php?u=5&t=abc';

    private function digest(array $o = []): array {
        return array_merge([
            'event' => ['id' => 10, 'name' => 'Fall <Sprint>', 'event_date' => '2026-10-11'],
            'daysOut' => 7, 'daysUntil' => 7,
            'items' => [
                ['kind' => 'tech_sheet', 'state' => 'todo', 'label' => 'Submit a tech sheet for #42', 'detail' => 'Every car needs a tech sheet for every event.',
                 'action' => ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=10']],
                ['kind' => 'car_tech', 'state' => 'todo', 'label' => 'Car tech for #42', 'detail' => '', 'action' => null],
            ],
        ], $o);
    }

    private function mail(array $o = []): array {
        return reminderEmail(['name' => 'Jordan Lee'], $this->digest($o), self::BASE, self::UNSUB);
    }

    public function testSubjectCountsTheThingsToDoAndNamesTheEvent(): void
    {
        $this->assertSame('WCMA reminder: 2 things to do before Fall <Sprint> (Oct 11)', $this->mail()['subject']);
        $one = $this->mail(['items' => [$this->digest()['items'][1]]]);
        $this->assertSame('WCMA reminder: 1 thing to do before Fall <Sprint> (Oct 11)', $one['subject']);
    }

    public function testBodyListsEachTodoWithAnAbsoluteLink(): void
    {
        $m = $this->mail();
        $this->assertStringContainsString("Hi Jordan Lee,\n\nFall <Sprint> is in 7 days (Sunday, October 11). You still have 2 things to do:", $m['text']);
        $this->assertStringContainsString("- Submit a tech sheet for #42\n  Every car needs a tech sheet for every event.\n  Submit tech sheet: https://x.test/classing/tech-sheets.php?action=new&car_id=3&event_id=10\n", $m['text']);
        $this->assertStringContainsString("- Car tech for #42\n", $m['text']);
        $this->assertStringContainsString('https://x.test/classing/index.php', $m['text']);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $m['text']);

        $this->assertStringContainsString('Fall &lt;Sprint&gt; is in 7 days', $m['html']);
        $this->assertStringContainsString('href="https://x.test/classing/tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10">Submit tech sheet</a>', $m['html']);
        $this->assertStringContainsString('href="https://x.test/classing/index.php"', $m['html']);
        $this->assertStringContainsString('cid:wcma-logo', $m['html']);
    }

    public function testTheDayBeforeSaysTomorrow(): void
    {
        $this->assertStringContainsString('Fall <Sprint> is tomorrow (Sunday, October 11).', $this->mail(['daysOut' => 2, 'daysUntil' => 1])['text']);
    }

    public function testEveryEmailCarriesAnUnsubscribeLinkAndOneClickHeaders(): void
    {
        $m = $this->mail();
        $this->assertSame(['List-Unsubscribe' => '<' . self::UNSUB . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], $m['headers']);
        $this->assertStringContainsString('Unsubscribe: ' . self::UNSUB, $m['text']);
        $this->assertStringContainsString('href="https://x.test/classing/unsubscribe.php?u=5&amp;t=abc">Unsubscribe from reminder emails</a>', $m['html']);
    }

    public function testNoBannedWording(): void
    {
        $m = $this->mail();
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m['subject'] . $m['html'] . $m['text']);
    }

    public function testAbsoluteUrls(): void
    {
        $this->assertSame('https://x.test/classing/garage.php?car=3', reminderAbsoluteUrl('https://x.test/classing/', '/garage.php?car=3'));
        $this->assertSame('https://other.test/a', reminderAbsoluteUrl('https://x.test/classing', 'https://other.test/a'));
    }

    public function testTheMailerSendsCustomHeaders(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../email-helpers.php'));
        $this->assertStringContainsString("foreach (\$message['headers'] ?? [] as \$name => \$value) {\n            \$mail->addCustomHeader((string)\$name, (string)\$value);", $src);
    }
}
