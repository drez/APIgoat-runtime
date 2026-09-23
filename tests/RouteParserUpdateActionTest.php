<?php
// Run: php tests/RouteParserUpdateActionTest.php   (from the runtime repo root)
//
// REGRESSION GUARD (2026-09-23): a GUI save of an EXISTING record needed 'a'.
// template screens.js posts every save to {Model}/update with the form
// serialized into 'd' (PK as a hidden <FirstPkPhpName> field) and no id in the
// URL. RouteParser::setAction() rewrote every id-less `update` to `create`, so
// AuthyMiddleware demanded 'a' and a 'rw' user got 403 [Model, a]. The action
// now follows the SAME signal as the emitted Service::saveUpdate()
// ($data[<pk>] from 'd', else the body 'i'); the JSON API is unchanged.

namespace App {
    /** Fake Propel peer: composite PK, first column IdWidget. */
    class WidgetPeer
    {
        public static function getTableMap()
        {
            return new class {
                public function getPrimaryKeys()
                {
                    $col = fn ($n) => new class($n) {
                        public function __construct(private string $n) {}
                        public function getPhpName() { return $this->n; }
                    };
                    return ['ID_WIDGET' => $col('IdWidget'), 'ID_PART' => $col('IdPart')];
                }
            };
        }
    }
}

namespace {
    require __DIR__ . '/autoload.php';
    require_once __DIR__ . '/../src/Middlewares/RouteParser.php';

    use ApiGoat\Middlewares\RouteParser;

    $fail = 0;
    function check(string $label, $got, $want): void
    {
        global $fail;
        if ($got === $want) {
            echo "  ok  {$label}\n";
            return;
        }
        $fail++;
        echo "  FAIL {$label}\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
    }

    /** Run the private setAction() on a pre-parsed args array; return the action. */
    function actionFor(array $args): string
    {
        $rp = new RouteParser();
        $p = new ReflectionProperty(RouteParser::class, 'args');
        $p->setValue($rp, $args + ['method' => 'POST', 'is_api' => false, 'id' => '']);
        $m = new ReflectionMethod(RouteParser::class, 'setAction');
        $m->invoke($rp);
        return $p->getValue($rp)['action'];
    }

    $gui = fn (array $body, string $id = '') => [
        'model' => 'Widget', 'action' => 'update', 'id' => $id, 'data' => $body,
    ];

    echo "GUI {Model}/update — screens.js request shape\n";
    check('edit: d carries the first PK -> update (w)',
        actionFor($gui(['d' => 'IdWidget=7&IdPart=2&Name=x', 'ui' => 'protoEditScreen'])), 'update');
    check('create: d carries an EMPTY pk -> create (a)',
        actionFor($gui(['d' => 'IdWidget=&Name=x', 'ui' => 'protoEditScreen'])), 'create');
    check('create: d without the pk -> create (a)',
        actionFor($gui(['d' => 'Name=x'])), 'create');
    check('only the SECOND pk column set -> create (Service reads the first only)',
        actionFor($gui(['d' => 'IdPart=2&Name=x'])), 'create');
    check('pk "0" is empty to the Service too -> create',
        actionFor($gui(['d' => 'IdWidget=0'])), 'create');
    check('body i names the record -> update',
        actionFor($gui(['i' => '7', 'd' => 'Name=x'])), 'update');
    check('id in the URL -> update',
        actionFor($gui(['d' => 'Name=x'], '7')), 'update');
    check('no body at all -> create',
        actionFor($gui([])), 'create');

    echo "Unknown model (no peer) keeps the old rule\n";
    check('no peer -> create even with an Id<Model> field',
        actionFor(['model' => 'Nope', 'action' => 'update', 'data' => ['d' => 'IdNope=5']]), 'create');

    echo "JSON API is unchanged\n";
    check('api update without URL id stays create (Api::setJson decides w/a itself)',
        actionFor(['model' => 'Widget', 'action' => 'update', 'is_api' => true,
            'data' => ['d' => 'IdWidget=7', 'i' => '7']]), 'create');

    echo "Helpers\n";
    check('firstPkPhpName = first PK column', RouteParser::firstPkPhpName('Widget'), 'IdWidget');
    check('firstPkPhpName rejects non-identifiers', RouteParser::firstPkPhpName('../x'), null);
    check('explicit pk name wins',
        RouteParser::guiBodyNamesRecord(['data' => ['d' => 'Code=AB']], 'Code'), true);

    echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
    exit($fail ? 1 : 0);
}
