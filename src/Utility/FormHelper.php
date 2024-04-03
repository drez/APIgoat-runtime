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

    public function setSearchVar($values, $model)
    {
        $search = null;
        $return = [];

        if ($values) {
            parse_str($values, $search);
        } elseif (!empty($_SESSION['mem']['search'][$model])) {
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
            $found = false;
            if (is_array($search['order'])) {
                foreach ($search['order'] as &$orders) {
                    if ($orders[$order['col']]) {
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

        $top_button = '';
        if (!$this->isChild) {
            $top_button = button(span(_('Top')), "class='scroll-top'");
            $perPage = $this->maxPerPage;
        } else {
            $perPage = $this->childMaxPerPage;
        }

        if (get_class($pmpoData) == 'PropelModelPager') {

            if ($pmpoData->haveToPaginate()) {
                $pager = div(
                    p('', "class='selectedCount'")
                        . p(span($perPage) . ' ' . _('per page') . ' - total ' . span($resultsCount), "class='count'")
                        . div(
                            href(span(_('Previous')), '#', "class='prev' data-direction='prev'")
                                . input('text', 'page', $search['page'], 'data-total="' . $pmpoData->getLastPage() . '"')
                                . p('/ ' . $pmpoData->getLastPage())
                                . href(span(_('Next')), '#', "class='next' data-direction='next'"),
                            '',
                            "id='{$this->TableName}Pager'"
                        ),
                    '',
                    "class='pagination-wrapper' data-total-item='{$resultsCount}'"
                );
            } else {
                $pager = div(
                    p('', "class='selectedCount'")
                        . p(span($resultsCount) . ' ' . $this->TableName, "class='count'"),
                    '',
                    "class='pagination-wrapper' data-total-item='{$resultsCount}'"
                );
            }
        }

        if (!$this->isChild) {
            $pagerRow =
                div(
                    $top_button
                        . $pager,
                    'cntPagerRow',
                    "class='navigation-wrapper'"
                );
        } else {
            $pagerRow = $pager;
        }

        return $pagerRow;
    }
}
