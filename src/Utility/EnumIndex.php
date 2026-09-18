<?php

namespace ApiGoat\Utility;

/**
 * Label → 0-based TINYINT index for a GoatCheese enum column, read from the
 * emitted Peer value set so it can never drift from the schema.
 *
 * GoatCheese enums are stored as 0-based TINYINTs: `enum(Yes, No)` is
 * `Yes = 0`, `No = 1`. Propel translates the label for you —
 * `filterByGone('No')` and `setGone('No')` both do the right thing — but RAW
 * SQL does not: `WHERE gone = 'No'` casts the string to `0`, which is `Yes`,
 * and returns the exact complement of what was asked for, with no error and
 * no warning. That trap has been hit repeatedly across the fleet.
 *
 * FOR RAW SQL BINDS ONLY. Propel's filterByX()/setX() take the label and must
 * keep taking it; passing them an int from here would be a second bug.
 *
 *     $sql = 'SELECT COUNT(*) FROM mail_message WHERE gone = :gone';
 *     $st->execute(['gone' => EnumIndex::of(MailMessagePeer::class, 'gone', 'No')]);
 *
 * An unknown column or an unknown label THROWS. That is the point: a typo
 * becomes a loud failure at build/test time instead of a query that silently
 * returns the wrong half of the table.
 */
final class EnumIndex
{
    /**
     * The 0-based storage index of $label in $column's value set.
     *
     * @param string $peerClass the emitted Peer, e.g. \App\MailMessagePeer::class
     * @param string $column    'gone' or the qualified 'mail_message.gone'
     * @throws \InvalidArgumentException on an unknown column or label
     */
    public static function of(string $peerClass, string $column, string $label): int
    {
        $set = self::valueSet($peerClass, $column);
        $i = \array_search($label, $set, true);
        if ($i === false) {
            throw new \InvalidArgumentException(\sprintf(
                'EnumIndex: %s has no value "%s" for column "%s" (declared: %s)',
                $peerClass,
                $label,
                $column,
                \implode(', ', $set)
            ));
        }

        return (int) $i;
    }

    /**
     * The indexes of several labels, in the order given — an `IN (…)` bind list.
     *
     * @param string[] $labels
     * @return int[]
     * @throws \InvalidArgumentException on an unknown column or label
     */
    public static function all(string $peerClass, string $column, array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            $out[] = self::of($peerClass, $column, (string) $label);
        }

        return $out;
    }

    /**
     * Every label of the column, in declaration order (index === position).
     *
     * @return string[]
     * @throws \InvalidArgumentException on an unknown class or column
     */
    public static function labels(string $peerClass, string $column): array
    {
        return self::valueSet($peerClass, $column);
    }

    /**
     * The Peer's value set for $column.
     *
     * The emitted `$enumValueSets` is keyed by the QUALIFIED column constant
     * ('mail_message.gone'), so a bare column name is matched on the part
     * after the dot — callers should not have to know which form the Peer
     * happens to use.
     *
     * @return string[]
     */
    private static function valueSet(string $peerClass, string $column): array
    {
        if (!\class_exists($peerClass) || !\method_exists($peerClass, 'getValueSets')) {
            throw new \InvalidArgumentException(
                'EnumIndex: ' . $peerClass . ' is not an emitted Peer class (no getValueSets())'
            );
        }
        /** @var array<string,array<int,string>> $sets */
        $sets = $peerClass::getValueSets();
        if (!\is_array($sets)) {
            throw new \InvalidArgumentException('EnumIndex: ' . $peerClass . '::getValueSets() did not return an array');
        }
        if (isset($sets[$column]) && \is_array($sets[$column])) {
            return \array_values(\array_map('strval', $sets[$column]));
        }
        foreach ($sets as $qualified => $values) {
            $pos = \strrpos((string) $qualified, '.');
            $bare = $pos === false ? (string) $qualified : \substr((string) $qualified, $pos + 1);
            if ($bare === $column && \is_array($values)) {
                return \array_values(\array_map('strval', $values));
            }
        }

        throw new \InvalidArgumentException(\sprintf(
            'EnumIndex: %s has no enum column "%s" (declared: %s)',
            $peerClass,
            $column,
            $sets === [] ? 'none' : \implode(', ', \array_keys($sets))
        ));
    }
}
