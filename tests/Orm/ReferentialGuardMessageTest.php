<?php
// Run: php tests/Orm/ReferentialGuardMessageTest.php
//
// F5 (review #13): ReferentialGuard::blockerFromMessage() turns the child table
// MySQL names in a 1451 / SQLSTATE 23000 refusal into the human label the
// pre-delete guard would have shown. Canned exception strings only — no DB.

require __DIR__ . '/../../src/Orm/ReferentialGuard.php';

use ApiGoat\Orm\ReferentialGuard;

$fail = 0;
function check(string $label, $got, $want): void
{
    if ($got === $want) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label (got " . var_export($got, true) . ")\n";
        $GLOBALS['fail']++;
    }
}

$labels = ['audit_log' => 'Audit log', 'invoice' => 'Invoice', 'client_event' => 'Client event'];

// The real PDO message (what Propel wraps and what the emitted catch appends
// the previous-exception chain to).
$pdo = "SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update"
     . " a parent row: a foreign key constraint fails (`gc_test`.`audit_log`,"
     . " CONSTRAINT `audit_log_FK_2` FOREIGN KEY (`id_creation`) REFERENCES `authy` (`id_authy`))";
check('names the child table', ReferentialGuard::blockerFromMessage($pdo, $labels), 'Audit log');

// Propel wraps it: "Unable to execute DELETE statement [...] " + the PDO text.
$wrapped = "Unable to execute DELETE statement [DELETE FROM `authy` WHERE id_authy=3] " . $pdo;
check('works through the Propel wrapper', ReferentialGuard::blockerFromMessage($wrapped, $labels), 'Audit log');

// Some MySQL builds omit the schema qualifier.
$noDb = "SQLSTATE[23000]: 1451 Cannot delete or update a parent row: a foreign key"
      . " constraint fails (`invoice`, CONSTRAINT `invoice_FK_1` FOREIGN KEY (`id_client`)"
      . " REFERENCES `client` (`id_client`))";
check('unqualified table name', ReferentialGuard::blockerFromMessage($noDb, $labels), 'Invoice');

check('a table with no label falls back to the generic message',
    ReferentialGuard::blockerFromMessage(str_replace('audit_log', 'some_other', $pdo), $labels), null);
check('an empty label map falls back',
    ReferentialGuard::blockerFromMessage($pdo, []), null);
check('a non-FK error is not matched',
    ReferentialGuard::blockerFromMessage('SQLSTATE[42S02]: Base table or view not found', $labels), null);
check('an empty message is not matched',
    ReferentialGuard::blockerFromMessage('', $labels), null);
check('a MySQL 8 DDL temp name is rejected',
    ReferentialGuard::blockerFromMessage(
        "a foreign key constraint fails (`db`.`#sql-1c4_9`, CONSTRAINT `x` FOREIGN KEY)",
        ['#sql-1c4_9' => 'nope']
    ), null);
check('the first (blocking) constraint wins when several are quoted',
    ReferentialGuard::blockerFromMessage($pdo . ' ' . $noDb, $labels), 'Audit log');
check('the MySQL wording is matched case-insensitively',
    ReferentialGuard::blockerFromMessage('A FOREIGN KEY CONSTRAINT FAILS (`db`.`invoice`, CONSTRAINT `x`', $labels),
    'Invoice');
check('the TABLE name is matched exactly (labels are keyed on the real name)',
    ReferentialGuard::blockerFromMessage('a foreign key constraint fails (`db`.`INVOICE`, CONSTRAINT `x`', $labels),
    null);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
