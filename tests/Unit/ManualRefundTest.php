<?php

declare(strict_types=1);

namespace Everysize\Checkout\Tests\Unit;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\ManualRefund;
use Everysize\Checkout\OrderLinks;
use PHPUnit\Framework\TestCase;

/**
 * The rules for refunds (R0 to R3, A1 to A9): what counts as refused, the open entry, reserving before
 * reading, repeating, settling, the stale tab, the automatic refund that sends at most once.
 */
final class ManualRefundTest extends TestCase
{
    private const KEY = 'abcdef0123456789abcdef0123456789';
    private const YOUNG = '2026-11-04 11:59:00';

    private StubClient $client;
    private FakeOrderLinks $links;
    private ManualRefund $refund;

    protected function setUp(): void
    {
        $this->client = new StubClient();
        $this->links = new FakeOrderLinks();
        $this->refund = new ManualRefund($this->client, $this->links, static function (): int {
            return strtotime(FakeOrderLinks::NOW);
        });
    }

    /**
     * @param array<int, string>|null $known
     */
    private function open(string $key = 'old-key_1', string $amount = '10.00', ?string $sent = FakeOrderLinks::OLD, string $created = FakeOrderLinks::OLD, ?array $known = [], string $resultId = ''): void
    {
        $this->links->openRefunds['5'] = ['key' => $key, 'amount' => $amount, 'created' => $created, 'sent' => $sent, 'knownIds' => $known, 'resultId' => $resultId];
    }

    private function send(string $amount = '10', string $known = '0.00', string $key = self::KEY): array
    {
        return $this->refund->refund('5', 'ABC123', $amount, false, $key, $known);
    }

    private function repeat(): array
    {
        return $this->refund->refund('5', 'ABC123', '', true, '');
    }

    private static function problem(int $status, string $reason = ''): ApiException
    {
        return new ApiException(sprintf('Refund failed: HTTP %d', $status), $status, $reason);
    }

    // --- R0: classification of the answer to the POST --------------------------------

    public function testTheOrderOfThingsReserveReadClaimSendBookDelete(): void
    {
        $this->client->list = [['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => '5.00']];

        $result = $this->send('10,5', '5.00');

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertSame([['ABC123', '10.50', self::KEY]], $this->client->posts);
        self::assertSame(
            ['open:'.self::KEY, 'known:R-0', 'claim:'.self::KEY, 'result:R-1', 'report:refund:R-1', 'close:'.self::KEY],
            $this->links->calls,
            'reserved, list stored, right to send taken over, refund ID noted, report written, entry removed'
        );
        self::assertSame([], $this->links->openRefunds);
    }

    public function testTheEntryIsReservedBeforeTheListIsReadAndBeforeThePost(): void
    {
        $atList = null;
        $atPost = null;
        $this->client->onList = function () use (&$atList): void {
            $atList = $this->links->openRefunds['5']['key'] ?? null;
        };
        $this->client->onPost = function () use (&$atPost): void {
            $atPost = $this->links->openRefunds['5'] ?? null;
        };

        $this->send('10');

        self::assertSame(self::KEY, $atList, 'reserved before the list is read');
        self::assertSame('10.00', $atPost['amount'] ?? null);
        self::assertNotNull($atPost['sent'] ?? null, 'the hand-over is counted before the POST');
        self::assertSame([], $atPost['knownIds'] ?? null, 'the list is stored with the entry');
    }

    public function testWhenTheEntryCannotBeReservedNothingIsReadOrSent(): void
    {
        $this->links->parallelEntry = ['key' => 'other-key', 'amount' => '10.00', 'created' => self::YOUNG, 'sent' => null, 'knownIds' => null, 'resultId' => ''];

        $result = $this->send('10');

        self::assertSame(ManualRefund::OTHER_AMOUNT, $result['outcome'], 'a parallel click / second admin');
        self::assertSame(['connect'], $this->client->calls);
    }

    /** @return array<string, array{ApiException}> */
    public static function safeRejections(): array
    {
        return [
            'paypal refused' => [self::problem(422, 'PAYPAL_REFUND_FAILED')],
            'amount invalid' => [self::problem(422, 'REFUND_AMOUNT_INVALID')],
            'not paid with everysize' => [self::problem(400, 'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS')],
        ];
    }

    /**
     * @dataProvider safeRejections
     */
    public function testASafeRejectionOfTheFirstAttemptRemovesTheEntryAndGivesANewKey(ApiException $e): void
    {
        $this->client->refundAnswer = $e;

        $result = $this->send('10');

        self::assertSame(ManualRefund::REJECTED, $result['outcome']);
        self::assertSame([], $this->links->openRefunds);
        self::assertSame([], $this->links->reports);
        self::assertNotSame(self::KEY, $result['newKey']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,64}$/', $result['newKey']);
    }

    /** A6: a refused repeat proves nothing about the first attempt. */
    /**
     * @dataProvider safeRejections
     */
    public function testASafeRejectionOfARepeatKeepsTheEntryOpen(ApiException $e): void
    {
        $this->open();
        $this->client->refundAnswer = $e;

        $result = $this->repeat();

        self::assertSame(ManualRefund::REJECTED_REPEAT, $result['outcome']);
        self::assertSame('old-key_1', $this->links->openRefunds['5']['key'] ?? null);
        self::assertSame([], $this->links->reports);
    }

    /** A never-sent entry (the request died after reserving) is a first attempt: its refusal removes it. */
    public function testASafeRejectionOfTheFirstSendOfAnOldNeverSentEntryRemovesIt(): void
    {
        $this->open('old-key_1', '10.00', null, FakeOrderLinks::OLD, null);
        $this->client->refundAnswer = self::problem(422, 'PAYPAL_REFUND_FAILED');
        $this->client->list = [['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => '3.00']];

        $result = $this->repeat();

        self::assertSame(ManualRefund::REJECTED, $result['outcome']);
        self::assertSame([], $this->links->openRefunds);
        self::assertContains('known:R-0', $this->links->calls, 'the list that was never stored is taken now');
    }

    /** @return array<string, array{ApiException}> */
    public static function proofless(): array
    {
        $cases = [];
        foreach ([300, 401, 403, 404, 408, 409, 425, 429, 500, 502, 503, 504] as $status) {
            $cases['HTTP '.$status] = [self::problem($status)];
        }
        $cases['status 0'] = [new ApiException('not reachable')];
        $cases['key reused'] = [self::problem(422, 'IDEMPOTENCY_KEY_REUSED')];
        $cases['422 without reason'] = [self::problem(422)];
        $cases['400 other reason'] = [self::problem(400, 'INVALID_REQUEST_BODY_FORMAT')];
        $cases['reason on another status'] = [self::problem(404, 'PAYPAL_REFUND_FAILED')];

        return $cases;
    }

    /**
     * @dataProvider proofless
     */
    public function testAnythingElseKeepsTheEntryOpenAndIsNeverCalledRefused(ApiException $e): void
    {
        $this->client->refundAnswer = $e;

        $result = $this->send('10');

        self::assertSame(ManualRefund::UNCLEAR, $result['outcome']);
        self::assertSame(self::KEY, $this->links->openRefunds['5']['key'] ?? null);
        self::assertSame('10.00', $this->links->openRefunds['5']['amount'] ?? null);
        self::assertNotNull($this->links->openRefunds['5']['sent']);
        self::assertSame([], $this->links->reports);
    }

    public function testAnUnexpectedErrorKeepsTheEntryOpen(): void
    {
        $this->client->refundAnswer = new \RuntimeException('boom');

        self::assertSame(ManualRefund::UNCLEAR, $this->send('10')['outcome']);
        self::assertArrayHasKey('5', $this->links->openRefunds);
    }

    /** A1: success needs proof. */
    /**
     * @dataProvider answersWithoutProof
     */
    public function testA2xxWithoutRefundIdAndWithoutAlreadyRefundedIsUnclear(array $answer): void
    {
        $this->client->refundAnswer = $answer;

        $result = $this->send('10');

        self::assertSame(ManualRefund::UNCLEAR, $result['outcome']);
        self::assertSame([], $this->links->reports);
        self::assertArrayHasKey('5', $this->links->openRefunds);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function answersWithoutProof(): array
    {
        return ['empty' => [[]], 'status only' => [['status' => 'COMPLETED']], 'empty id' => [['refundId' => '', 'status' => 'COMPLETED']], 'other status' => [['status' => 'PENDING', 'amount' => '10.00']]];
    }

    public function testTokenFailureBeforeThePostWithdrawsTheEntryOfThisRequest(): void
    {
        $this->client->refundAnswer = new ApiException('token failed', 503, '', true);

        $result = $this->send('10');

        self::assertSame(ManualRefund::NOT_SENT, $result['outcome']);
        self::assertSame([], $this->links->openRefunds, 'created in this request: withdrawn');
    }

    public function testTokenFailureBeforeThePostLeavesAnOlderEntryOpen(): void
    {
        $this->open();
        $this->client->refundAnswer = new ApiException('token failed', 503, '', true);

        $result = $this->repeat();

        self::assertSame(ManualRefund::NOT_SENT, $result['outcome']);
        self::assertSame('old-key_1', $this->links->openRefunds['5']['key'] ?? null);
    }

    public function testNoTokenAtAllSendsNothingAndStoresNothing(): void
    {
        $this->client->connectError = new ApiException('bad credentials', 401);

        $result = $this->send('10');

        self::assertSame(ManualRefund::NOT_SENT, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    // --- R3 and A3: amount, stale tab, reading the list ------------------------------

    /** @return array<string, array{string}> */
    public static function badAmounts(): array
    {
        return ['empty' => [''], 'blank' => ['  '], 'zero' => ['0'], 'negative' => ['-5'], 'text' => ['abc'], 'three decimals' => ['10,555'], 'thousands guess' => ['1.234']];
    }

    /**
     * @dataProvider badAmounts
     */
    public function testAnEmptyOrInvalidAmountNeverRefunds(string $input): void
    {
        $result = $this->send($input);

        self::assertSame(ManualRefund::INVALID_AMOUNT, $result['outcome']);
        self::assertSame([], $this->client->calls);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAStaleTabSendsNothingAndWithdrawsTheNewEntry(): void
    {
        $this->client->list = [['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => '5.00']];

        $result = $this->send('10', '0.00');

        self::assertSame(ManualRefund::STALE, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAPageWithoutTheKnownSumSendsNothing(): void
    {
        self::assertSame(ManualRefund::STALE, $this->refund->refund('5', 'ABC123', '10', false, self::KEY, null)['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAMatchingKnownSumGoesThroughAndFailedRefundsDoNotCount(): void
    {
        $this->client->list = [
            ['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => '5.00'],
            ['refundId' => 'R-X', 'status' => 'PENDING', 'amount' => '2.50'],
            ['refundId' => 'R-F', 'status' => 'FAILED', 'amount' => '99.00'],
        ];

        self::assertSame(ManualRefund::DONE, $this->send('10', '7.50')['outcome']);
    }

    /** A11: amounts from the API come as "1,234.56". */
    public function testAmountsWithAThousandsSeparatorAreSummedRight(): void
    {
        $this->client->list = [['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => '1,234.56']];

        self::assertSame(ManualRefund::DONE, $this->send('10', '1234.56')['outcome']);
    }

    public function testAnAmountInTheListThatCannotBeReadMakesItUnusable(): void
    {
        $this->client->list = [['refundId' => 'R-0', 'status' => 'COMPLETED', 'amount' => 'ten']];

        $result = $this->send('10', '0.00');

        self::assertSame(ManualRefund::LIST_UNREADABLE, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAnUnreadableListSendsNothingAndWithdrawsTheNewEntry(): void
    {
        $this->client->listError = self::problem(503);

        $result = $this->send('10');

        self::assertSame(ManualRefund::LIST_UNREADABLE, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    // --- R1: the open entry ------------------------------------------------------------

    public function testNoOtherRefundWhileOneIsOpen(): void
    {
        $this->open();

        foreach (['10', '5', ''] as $amount) {
            self::assertSame(ManualRefund::OTHER_AMOUNT, $this->send($amount)['outcome']);
        }
        self::assertSame([], $this->client->calls, 'no call at all');
    }

    /** A5: the 150 s count from the last hand-over, not from the creation. */
    public function testYoungerThanTwoAndAHalfMinutesSinceTheHandOverNothingIsSent(): void
    {
        $this->open('old-key_1', '10.00', self::YOUNG, FakeOrderLinks::OLD);

        $result = $this->repeat();

        self::assertSame(ManualRefund::RUNNING, $result['outcome']);
        self::assertSame([], $this->client->calls);
    }

    public function testAnEntryCreatedJustNowAndNeverSentAlsoWaits(): void
    {
        $this->open('old-key_1', '10.00', null, self::YOUNG, null);

        self::assertSame(ManualRefund::RUNNING, $this->repeat()['outcome']);
        self::assertSame([], $this->client->calls);
    }

    /** A5: parallel repeats - only the one who takes over the right to send sends. */
    public function testWhoLosesTheHandOverSendsNothing(): void
    {
        $this->open();
        $this->links->claimLost = true;

        $result = $this->repeat();

        self::assertSame(ManualRefund::RUNNING, $result['outcome']);
        self::assertSame([], $this->client->posts);
    }

    public function testRepeatingTwiceInARowSendsOnce(): void
    {
        $this->open();
        $this->client->refundAnswer = self::problem(504);

        self::assertSame(ManualRefund::UNCLEAR, $this->repeat()['outcome']);
        self::assertSame(ManualRefund::RUNNING, $this->repeat()['outcome'], 'the second click comes within 150 s of the first hand-over');
        self::assertCount(1, $this->client->posts);
    }

    public function testRepeatingWithoutAnOpenEntryDoesNothing(): void
    {
        self::assertSame(ManualRefund::NOTHING_OPEN, $this->repeat()['outcome']);
        self::assertSame([], $this->client->calls);
    }

    public function testRepeatingReadsTheListFirstAndSendsTheSameKeyAndAmount(): void
    {
        $this->open();

        $result = $this->repeat();

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertSame(['connect', 'list', 'post'], $this->client->calls);
        self::assertSame([['ABC123', '10.00', 'old-key_1']], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testRepeatingWithAnUnreadableListSendsNothingAndStaysOpen(): void
    {
        $this->open();
        $this->client->listError = self::problem(502);

        $result = $this->repeat();

        self::assertSame(ManualRefund::LIST_UNREADABLE, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertArrayHasKey('5', $this->links->openRefunds);
    }

    /** A4: only a refund that is new since the entry was made. */
    public function testRepeatingFindsANewMatchingRefundAndClosesAsSuccessWithoutSending(): void
    {
        $this->open('old-key_1', '10.00', FakeOrderLinks::OLD, FakeOrderLinks::OLD, ['R-OLD']);
        $this->client->list = [['refundId' => 'R-77', 'status' => 'COMPLETED', 'amount' => '10.00', 'currency' => 'EUR']];

        $result = $this->repeat();

        self::assertSame(ManualRefund::FOUND, $result['outcome']);
        self::assertSame('R-77', $result['refundId']);
        self::assertSame([], $this->client->posts);
        self::assertSame(['result:R-77', 'report:refund:R-77', 'close:old-key_1'], $this->links->calls);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAPendingRefundCountsAsAMatchToo(): void
    {
        $this->open();
        $this->client->list = [['refundId' => 'R-77', 'status' => 'PENDING', 'amount' => '10.00']];

        self::assertSame(ManualRefund::FOUND, $this->repeat()['outcome']);
    }

    public function testAnOldForeignRefundOfTheSameAmountIsNeverTakenAsTheOwn(): void
    {
        // Listed when the entry was made: somebody refunded the same amount in PayPal before.
        $this->open('old-key_1', '10.00', FakeOrderLinks::OLD, FakeOrderLinks::OLD, ['R-77']);
        $this->client->list = [['refundId' => 'R-77', 'status' => 'COMPLETED', 'amount' => '10.00']];

        $result = $this->repeat();

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertCount(1, $this->client->posts, 'not a match: the repeat goes out');
    }

    public function testARefundTheShopKnowsOrOfAnotherAmountOrFailedIsNoMatch(): void
    {
        $this->open();
        $this->links->knownRefundIds = ['R-77'];
        $this->client->list = [
            ['refundId' => 'R-77', 'status' => 'COMPLETED', 'amount' => '10.00'],
            ['refundId' => 'R-78', 'status' => 'COMPLETED', 'amount' => '3.00'],
            ['refundId' => 'R-79', 'status' => 'FAILED', 'amount' => '10.00'],
        ];

        self::assertSame(ManualRefund::DONE, $this->repeat()['outcome']);
        self::assertCount(1, $this->client->posts);
    }

    public function testTheRestMatchesAnyNewRefund(): void
    {
        $this->open('auto-decline-ABC123', '');
        $this->client->list = [['refundId' => 'R-80', 'status' => 'COMPLETED', 'amount' => '1,033.30']];

        $result = $this->repeat();

        self::assertSame(ManualRefund::FOUND, $result['outcome']);
        self::assertSame([], $this->client->posts);
    }

    public function testARepeatThatFailsUnclearAgainStaysOpen(): void
    {
        $this->open();
        $this->client->refundAnswer = self::problem(404);

        self::assertSame(ManualRefund::UNCLEAR, $this->repeat()['outcome']);
        self::assertSame('old-key_1', $this->links->openRefunds['5']['key']);
    }

    // --- A8: finishing --------------------------------------------------------------------

    public function testAnEntryThatKnowsItsRefundIdIsFinishedAndNeverSentAgain(): void
    {
        $this->open('old-key_1', '10.00', FakeOrderLinks::OLD, FakeOrderLinks::OLD, [], 'R-5');

        $result = $this->repeat();

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertSame([], $this->client->calls, 'not even the token');
        self::assertSame([['5', OrderLinks::KIND_REFUND, 'R-5', OrderLinks::RESULT_SENT]], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAFailedDeleteAfterTheReportDoesNotSendAgainNextTime(): void
    {
        $links = new class extends FakeOrderLinks {
            public int $closes = 0;

            public function closeRefund(string $orderId, string $key, string $amount): void
            {
                if (++$this->closes === 1) {
                    throw new \RuntimeException('database gone');
                }
                parent::closeRefund($orderId, $key, $amount);
            }
        };
        $refund = new ManualRefund($this->client, $links, static function (): int {
            return strtotime(FakeOrderLinks::NOW);
        });

        $first = $refund->refund('5', 'ABC123', '10', false, self::KEY, '0.00');
        self::assertSame(ManualRefund::DONE, $first['outcome']);
        self::assertStringContainsString('could not be removed', $first['message']);
        self::assertSame('R-1', $links->openRefunds['5']['resultId']);
        self::assertCount(1, $links->reports);

        $links->openRefunds['5']['sent'] = FakeOrderLinks::OLD;
        $next = $refund->refund('5', 'ABC123', '', true, '');

        self::assertSame(ManualRefund::DONE, $next['outcome']);
        self::assertCount(1, $this->client->posts, 'sent once');
        self::assertCount(1, $links->reports, 'booked once');
        self::assertSame([], $links->openRefunds);
    }

    public function testAFailingReportKeepsTheEntryOpenWithItsRefundId(): void
    {
        $links = new class extends FakeOrderLinks {
            public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
            {
                throw new \RuntimeException('database gone');
            }
        };
        $refund = new ManualRefund($this->client, $links);

        $result = $refund->refund('5', 'ABC123', '10', false, self::KEY, '0.00');

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertStringContainsString('not noted', $result['message']);
        self::assertSame('R-1', $links->openRefunds['5']['resultId'], 'no report, the entry stays and is finished later without sending');
    }

    public function testAnAlreadyRefundedAnswerIsBookedAsKnownWithoutHistory(): void
    {
        $this->client->refundAnswer = ['status' => 'ALREADY_REFUNDED'];

        $result = $this->send('10');

        self::assertSame(ManualRefund::ALREADY_REFUNDED, $result['outcome']);
        self::assertSame([['5', OrderLinks::KIND_REFUND, 'ALREADY_REFUNDED', OrderLinks::RESULT_KNOWN]], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testTheSameRefundIdIsBookedOnce(): void
    {
        $this->links->reports[] = ['5', OrderLinks::KIND_REFUND, 'R-1', OrderLinks::RESULT_SENT];
        $this->send('10');

        self::assertCount(1, array_filter($this->links->reports, static function (array $r): bool {
            return $r[2] === 'R-1';
        }));
    }

    // --- A7: mark as settled --------------------------------------------------------------

    public function testMarkingAsSettledSendsNothingAndKeepsTheAutomaticRefundAway(): void
    {
        $this->open();

        $result = $this->refund->markSettled('5', 'old-key_1');

        self::assertSame(ManualRefund::SETTLED, $result['outcome']);
        self::assertSame([], $this->client->calls);
        self::assertSame(['report:refund:settled-old-key_1', 'remove:old-key_1'], $this->links->calls);
        self::assertSame([['5', OrderLinks::KIND_REFUND, 'settled-old-key_1', OrderLinks::RESULT_SETTLED]], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testMarkingAsSettledWaitsForTheSameAgeAsRepeating(): void
    {
        $this->open('old-key_1', '10.00', self::YOUNG);

        self::assertSame(ManualRefund::RUNNING, $this->refund->markSettled('5', 'old-key_1')['outcome']);
        self::assertArrayHasKey('5', $this->links->openRefunds);
        self::assertSame([], $this->links->reports);
    }

    public function testMarkingAsSettledOnlyRemovesTheEntryTheMerchantSaw(): void
    {
        $this->open('newer-key_2');

        self::assertSame(ManualRefund::NOTHING_OPEN, $this->refund->markSettled('5', 'old-key_1')['outcome']);
        self::assertArrayHasKey('5', $this->links->openRefunds);
        self::assertSame([], $this->links->reports);
    }

    public function testIfTheDeleteLosesTheRaceTheReportIsTakenBack(): void
    {
        $links = new class extends FakeOrderLinks {
            public function removeOpenRefundIfOld(string $orderId, string $key, int $seconds): bool
            {
                return false; // somebody handed it over in between
            }
        };
        $links->openRefunds['5'] = ['key' => 'old-key_1', 'amount' => '10.00', 'created' => FakeOrderLinks::OLD, 'sent' => FakeOrderLinks::OLD, 'knownIds' => [], 'resultId' => ''];
        $refund = new ManualRefund($this->client, $links, static function (): int {
            return strtotime(FakeOrderLinks::NOW);
        });

        self::assertSame(ManualRefund::RUNNING, $refund->markSettled('5', 'old-key_1')['outcome']);
        self::assertSame([], $links->reports, 'no stray report that would block the automatic refund');
        self::assertArrayHasKey('5', $links->openRefunds);
    }

    public function testMarkingAsSettledNeedsAnOpenEntry(): void
    {
        self::assertSame(ManualRefund::NOTHING_OPEN, $this->refund->markSettled('5')['outcome']);
    }

    // --- Automatic refund ----------------------------------------------------------------------

    public function testAutomaticRefundReadsTheListSendsTheFixedKeyAndBooksItAsAutomatic(): void
    {
        $result = $this->refund->autoRefund('5', 'ABC123');

        self::assertSame(ManualRefund::DONE, $result['outcome']);
        self::assertSame([['ABC123', null, 'auto-decline-ABC123']], $this->client->posts);
        self::assertSame(['open:auto-decline-ABC123', 'known:', 'claim:auto-decline-ABC123', 'result:R-1', 'report:refund:auto-decline', 'close:auto-decline-ABC123'], $this->links->calls);
    }

    public function testAutomaticRefundDoesNotSendWhenPaypalListsARefund(): void
    {
        foreach (['COMPLETED', 'PENDING'] as $status) {
            $this->setUp();
            $this->client->list = [['refundId' => 'R-1', 'status' => $status, 'amount' => '5.00']];

            self::assertSame(ManualRefund::EXISTS, $this->refund->autoRefund('5', 'ABC123')['outcome'], $status);
            self::assertSame([], $this->client->posts);
            self::assertSame([], $this->links->openRefunds);
        }
    }

    public function testAutomaticRefundWithAnUnreadableListSendsNothingAndWithdrawsTheEntry(): void
    {
        $this->client->listError = self::problem(500);

        self::assertSame(ManualRefund::LIST_UNREADABLE, $this->refund->autoRefund('5', 'ABC123')['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([], $this->links->openRefunds);
    }

    /** A2: the automatic run sends at most once, whatever the state of an open entry. */
    public function testTheAutomaticRunNeverSendsForAnOpenEntry(): void
    {
        foreach ([FakeOrderLinks::OLD, self::YOUNG] as $sent) {
            $this->setUp();
            $this->open('auto-decline-ABC123', '', $sent);

            self::assertSame(ManualRefund::OTHER_AMOUNT, $this->refund->autoRefund('5', 'ABC123')['outcome']);
            self::assertSame(ManualRefund::WAITING, $this->refund->checkOpenAutomatic('5', 'ABC123')['outcome']);
            self::assertSame([], $this->client->posts);
        }
    }

    public function testAnOpenAutomaticEntryIsClosedWhenTheListShowsANewRefund(): void
    {
        $this->open('auto-decline-ABC123', '', FakeOrderLinks::OLD, FakeOrderLinks::OLD, ['R-OLD']);
        $this->client->list = [['refundId' => 'R-OLD', 'status' => 'COMPLETED', 'amount' => '1.00'], ['refundId' => 'R-9', 'status' => 'COMPLETED', 'amount' => '33.30']];

        $result = $this->refund->checkOpenAutomatic('5', 'ABC123');

        self::assertSame(ManualRefund::FOUND, $result['outcome']);
        self::assertSame([], $this->client->posts);
        self::assertSame([['5', OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, OrderLinks::RESULT_SENT]], $this->links->reports);
        self::assertSame([], $this->links->openRefunds);
    }

    public function testAnOpenAutomaticEntryWithAnUnreadableListWaits(): void
    {
        $this->open('auto-decline-ABC123', '');
        $this->client->listError = self::problem(503);

        self::assertSame(ManualRefund::LIST_UNREADABLE, $this->refund->checkOpenAutomatic('5', 'ABC123')['outcome']);
        self::assertArrayHasKey('5', $this->links->openRefunds);
    }

    public function testTheAutomaticRunLeavesAnOpenRefundOfAPersonAlone(): void
    {
        $this->open('manual-key', '10.00');

        self::assertSame(ManualRefund::OTHER_AMOUNT, $this->refund->autoRefund('5', 'ABC123')['outcome']);
        self::assertSame([], $this->client->calls);
    }

    public function testASafeRejectionOfTheAutomaticRefundIsFinal(): void
    {
        $this->client->refundAnswer = self::problem(422, 'PAYPAL_REFUND_FAILED');

        $result = $this->refund->autoRefund('5', 'ABC123');

        self::assertSame(ManualRefund::REJECTED, $result['outcome']);
        self::assertSame([], $this->links->openRefunds);
    }

    // --- Misc ---------------------------------------------------------------------------------------

    public function testAnInvalidKeyIsReplacedBeforeItReachesTheHeader(): void
    {
        $this->send('10', '0.00', "abc\r\nX-Evil: 1");

        self::assertMatchesRegularExpression('/^manual-[0-9a-f]{16}$/', $this->client->posts[0][2]);
    }

    public function testAnOldUnknownResultIsNoOpenEntry(): void
    {
        // Old reports with the result "unknown" are plain display: only a row of the open-refund table is an entry.
        self::assertNull($this->links->getOpenRefund('5'));
    }

    public function testPrecheckAnswersWithoutSendingAnything(): void
    {
        self::assertNull($this->refund->precheck('5', '10,00', false));
        self::assertSame(ManualRefund::INVALID_AMOUNT, $this->refund->precheck('5', '', false));
        self::assertSame(ManualRefund::NOTHING_OPEN, $this->refund->precheck('5', '10', true));

        $this->open();
        self::assertNull($this->refund->precheck('5', '', true));
        self::assertSame(ManualRefund::OTHER_AMOUNT, $this->refund->precheck('5', '10', false));
        self::assertSame([], $this->client->calls);
    }
}
