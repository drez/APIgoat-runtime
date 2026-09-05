<?php

namespace ApiGoat\Utility;

trait FormHelper
{

    private function setCriteria($value, &$criteria)
    {
        // starts with !, invert
        if (strpos($value, '!') === 0) {
            $criteria = $this->invertCriteria($criteria);
            $value = substr($value, 1);
        }

        if ($criteria == \Criteria::LIKE) {
            if (\substr($value, 0, 1)  === '%' || \substr($value, -1)  === '%') {
                return $value;
            } else {
                return '%' . $value . '%';
            }
        }

        return $value;
    }

    private function invertCriteria($criteria)
    {
        switch ($criteria) {
            case \Criteria::IN:
                return \Criteria::NOT_IN;
                break;
            case \Criteria::LIKE:
                return \Criteria::NOT_LIKE;
                break;
            case \Criteria::EQUAL:
                return \Criteria::NOT_EQUAL;
                break;
            case \Criteria::CONTAINS_SOME:
                return \Criteria::CONTAINS_NONE;
                break;
        }
    }

    public function setSearchVar(string $values, string $model)
    {
        $search = null;
        $return = [];

        if (!empty($values)) {
            parse_str($values, $search);
        }

        $_SESSION['mem']['search'][$model] = array_filter($_SESSION['mem']['search'][$model] ?? []);

        if (empty($search) && !empty($_SESSION['mem']['search'][$model])) {
            return $_SESSION['mem']['search'][$model];
        }

        if (is_array($search)) {
            array_walk($search, function ($value, $key)  use (&$return) {
                if (is_array($value) && \strstr($value[0], ',')) {
                    $return[$key] = \explode(',', $value[0]);
                } elseif (is_array($value) && !empty($value[0])) {
                    $return[$key] = $value;
                } elseif (!is_array($value)) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $return[$key] = $value;
                    }
                }
            });
        }

        $_SESSION['mem']['search'][$model] = $return;

        return $return;
    }

    public function setPageVar($value, $model)
    {
        if (\is_numeric($value)) {
            $_SESSION['mem']['page'][$model] = $value;
        } elseif (!empty($_SESSION['mem']['page'][$model])) {
            $value = $_SESSION['mem']['page'][$model];
        } else {
            $value = 1;
        }
        return $value;
    }

    public function setOrderVar($values, $model)
    {
        $search['order'] = null;

        if (!empty($_SESSION['mem']['order'][$model])) {
            $search['order'] = $_SESSION['mem']['order'][$model];
        }
        if ($values) {
            $order = json_decode($values, true);
            if (!is_array($order) || !isset($order['col']) || !is_string($order['col'])) {
                return $search['order'];
            }
            // '*' clear-all sentinel: drop every stored ordering for this
            // list so it falls back to its schema default order.
            if ($order['col'] === '*') {
                unset($_SESSION['mem']['order'][$model]);
                return null;
            }
            // C8: whatever lands here is stored in the session and later
            // interpolated into an ORDER BY and into the list's inline sort
            // JS. Only accept the shape the client can legitimately send:
            // a column identifier (optionally Relation.Column[.locale]) and
            // one of the three sort senses. Anything else leaves the stored
            // ordering untouched. NOT whitelisted against the model's TableMap:
            // legitimate sort keys include dotted relation columns, child-column
            // aliases and the virtual i18n columns, none of which are the
            // model's own PhpNames — Propel's own orderBy() rejects a column it
            // cannot resolve, so this guard is about the shape, not the map.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*){0,2}$/', $order['col'])) {
                return $search['order'];
            }
            $order['sens'] = strtolower((string) ($order['sens'] ?? ''));
            if (!in_array($order['sens'], ['', 'asc', 'desc'], true)) {
                return $search['order'];
            }
            $found = false;
            if (is_array($search['order'])) {
                foreach ($search['order'] as &$orders) {
                    if (!empty($orders[$order['col']])) {
                        if ($order['sens'] == '') {
                            unset($orders[$order['col']]);
                        } else {
                            $orders[$order['col']] = $order['sens'];
                        }
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                $search['order'][] = [$order['col'] => $order['sens']];
            }

            $_SESSION['mem']['order'][$model] = $search['order'];
        }

        return $search['order'];
    }

    public function getPager($pmpoData, $resultsCount, $search)
    {

        if (get_class($pmpoData) != 'PropelModelPager') {
            return '';
        }

        $cur = (int) ($search['page'] ?? 1);
        if ($cur < 1) {
            $cur = 1;
        }
        $last = (int) $pmpoData->getLastPage();
        if ($last < 1) {
            $last = 1;
        }

        // Hidden carrier — the vanilla list client reads #page value +
        // data-total to drive prev/next (server contract §5). Kept even
        // when there is a single page so the client degrades gracefully.
        $pageInput = input('hidden', 'page', $cur, 'data-total="' . $last . '"');

        // Guideline pager copy: "{shown} of {total}" (06-countries-list /
        // desktop/02-countries). `shown` is the upper bound of the rows
        // visible on the current page so a single-page list reads
        // "N of N" and a paged one reads "20 of 22" → "22 of 22".
        $perPage = (int) (method_exists($pmpoData, 'getMaxPerPage') ? $pmpoData->getMaxPerPage() : 0);
        $shown = ($perPage > 0) ? min($cur * $perPage, (int) $resultsCount) : (int) $resultsCount;
        $countLabel = span(
            $shown . ' ' . _('of') . ' ' . $resultsCount,
            "class='va-mob-pager-count'"
        );

        if ($pmpoData->haveToPaginate()) {
            $prevCls = ($cur <= 1) ? 'pgr-btn prev disabled' : 'pgr-btn prev';
            $nextCls = ($cur >= $last) ? 'pgr-btn next disabled' : 'pgr-btn next';
            $controls = div(
                button("<i class='ri-arrow-left-s-line'></i>", "class='{$prevCls}' data-direction='prev'")
                    . button("<i class='ri-arrow-right-s-line'></i>", "class='{$nextCls}' data-direction='next'"),
                '',
                "class='pgr' id='{$this->TableName}Pager'"
            );
            $pager = div(
                $countLabel . $pageInput . $controls,
                '',
                "class='va-mob-pager pagination-wrapper' data-total-item='{$resultsCount}'"
            );
        } else {
            $pager = div(
                $countLabel
                    . $pageInput
                    . div('', '', "class='pgr' id='{$this->TableName}Pager'"),
                '',
                "class='va-mob-pager pagination-wrapper' data-total-item='{$resultsCount}'"
            );
        }

        return $pager;
    }
}
