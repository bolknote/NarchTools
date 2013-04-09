<?
// error_reporting(E_ALL);
mb_internal_encoding('utf-8');

define('B_ID', 'id');
define('B_SURNAME', 'f2');
define('B_NAME', 'f3');
define('B_PATRONYMIC', 'f4');
define('B_BIRTHDAY', 'f5');

define('B_SPECIAL_SURNAME_WEIGHT', '_surname_weight_');

function B_split($lastname) {
    $len = mb_strlen($lastname);
    $chunks = [];

    $lastname = mb_strtolower($lastname);

    for ($start = 0; $start<$len; $start++) {
        for ($end = $start + 1; $end<=$len; $end++) {
            $chunks[] = mb_substr($lastname, $start, $end - $start);
        }
    }

    return array_unique($chunks);
}

function B_simplegetpage($query, $page) {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "http://obd-memorial.ru/Image2/jsonservlet?callback=jQuery0",
        CURLOPT_HEADER => 0,
        CURLOPT_POST => 1,
        CURLOPT_USERAGENT => 'Opera/9.80 (Macintosh; Intel Mac OS X 10.8.2) Presto/2.12.388 Version/12.12',
        CURLOPT_HTTPHEADER => [
            "X-Requested-With: XMLHttpRequest",
            "Accept-Encoding: gzip, deflate"
        ],
        CURLOPT_RETURNTRANSFER => 1,
    ]);

    $fields = ['json', 'entity', 'family', 'name', 'middlename', 'year', 'placebirth', 'draft', 'lastplace',
    'rank', 'post', 'dateout', 'placeout', 'hospital', 'from', 'add', 'latname', 'lagnum', 'placegrave', 'capt',
    'camp', 'datedeath', 'country', 'region', 'place', 'fulltext', 'page', 'size', 'vpp', 'vppname', 'datein',
    'vppfrom', 'whereout', 'outaddr', 'comnumber', 'source', 'fund', 'list', 'case', 'numreport', 'datareport', 
    'typereport', 'sourcereport', 'partreport', 'namereport'];

    $values = [
        'json'   => 1,
        'entity' => '00000001111110',
        'family' => 'P~' . rawurlencode($query['family']),
        'page'   => $page,
        'size'   => 100,
    ];

    $postdata = $values + array_combine($fields, array_fill(0, count($fields), ''));

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));

    return curl_exec($ch);
}

function B_getpage($query, $page) {

    for ($i = 0; $i<4; $i++) {
        $result = B_simplegetpage($query, $page);
        if ($result !== false) {
            break;
        }

        B_log('Retry #'. ($i+1));

        usleep(500000); // 0.5 s
    }

    if ($result !== false) {
        $result = rtrim($result, ')');
        $result = substr($result, strlen('jQuery0('));

        return json_decode($result, true);
    }

    return false;
}

function B_log($str) {
    // echo join(' ', func_get_args()), "\n";
    // flush();
}

function B_getpages($query) {
    $pages = [];

    for ($pagenum = 1;;$pagenum++) {
        B_log('Get page #', $pagenum);

        $result = B_getpage($query, $pagenum);

        // выход на появление поля result (['result' => 'OK']) 
        if (isset($result['result'])) {
            break;
        }

        // если повторяеся та же страница, значит достигли конца
        if ($pages && $pages[$pagenum-2] == $result) {
            break;
        }

        $pages[] = $result;

        // если на текущей странице результатов меньше ста, закончили
        if ($result !== false && count($result) < 100) {
            break;
        }
    }

    return $pages;
}

function B_filterpages($pages, $surname) {
    $people = [];

    $surname = mb_strtolower($surname);
    $surname1char = mb_substr($surname, 0, 1);

    $trans = [
        '[...]' => '.',
        '…' => '.',
    ];

    foreach ($pages as $page) {
        if ($page) {
            foreach ($page as $person) {
                if (strpos($person[B_SURNAME], '.') !== false || strpos($person[B_NAME], '…') !== false) {

                    $personsurname = mb_strtolower(strtr($person[B_SURNAME], $trans));
                    $surnamemask = '/^(' . preg_replace('/\.+/', ').+(', $personsurname . ')$/us');

                    if (preg_match($surnamemask, $surname, $r)) {
                        // количество совпавших букв
                        $matchcnt = array_sum(array_map('mb_strlen', array_slice($r, 1)));

                        $person[B_SPECIAL_SURNAME_WEIGHT] = $matchcnt / mb_strlen($surname) +
                               .11 * (mb_substr($personsurname, 0, 1) == $surname1char); // + .11 за совпадение первой буквы

                        $people[] = $person;
                    }
                }
            }
        }
    }

    return $people;
}

function B_searchbysurname($surname) {
    $persons = [];
    foreach (B_split($surname) as $chunked) {
        $persons = array_merge($persons, B_filterpages(B_getpages(['family' => $chunked]), $surname));
    }

    return $persons;
}

function B_filterby($persons, $year, $diviation, $name, $patronymic) {
    return array_filter($persons, function($person) use ($year, $diviation, $name, $patronymic) {

        // год и отклонение
        if ($year && isset($person[B_BIRTHDAY])) {
            $pyear = explode('.', $person[B_BIRTHDAY])[2];

            if ($year + $diviation < $pyear || $year - $diviation > $pyear) return false;

            $pyearmask = '/^'.preg_replace('/[_\?]/', '.', $pyear).'$/';

            if (!preg_match($pyearmask, $year)) {
                return false;
            }
        }

        // имя и отчество
        if ($name || $patronymic) {
            $fields = [
                B_NAME => 'name',
                B_PATRONYMIC => 'patronymic',
            ];

            foreach ($fields as $cons => $var) {
                if ($$var && isset($person[$cons])) {
                    $pname = mb_strtolower($person[$cons]);

                    if (strpos($pname, 'неразборчиво') !== false) {
                        $namemask = '.+';
                        $tocompare = $$var;
                    } else {
                        $namemask = '^' . preg_replace('/(?:\.|\[.+?\]|\?)+/', '.+', $pname);

                        $len = mb_strlen($$var);
                        if (mb_substr($$var, $len - 1) != '?') {
                            $namemask .= '$';
                            $tocompare = $$var;
                        } else {
                            $tocompare = mb_substr($$var, 0, $len - 1);
                        }
                    }

                    if (!preg_match("/$namemask/su", $tocompare)) {
                        return false;
                    }
                }
            }
        }

        return true;
    });
}

function B_sortbysurname($persons) {
    usort($persons, function($a, $b) {
        return $a[B_SPECIAL_SURNAME_WEIGHT] < $b[B_SPECIAL_SURNAME_WEIGHT] ? 1 : -1;

        return 0;
    });

    return $persons;
}

function B_getby($surname, $name, $patronymic, $year = false, $diviation = 0) {
    $persons = B_searchbysurname($surname);
    $persons = B_filterby($persons, $year, $diviation, $name, $patronymic);

    $persons = B_sortbysurname($persons);

    return $persons; 
}

$persons = B_getby(
    'савенко',
    'к?',
    'д?'
    // 1913
    // 1 // +/-
);

foreach ($persons as $person) {
    $birthday = $person[B_BIRTHDAY] ? $person[B_BIRTHDAY] : '__.__.____';

    printf(
        "%s\t%s\t%s\t%s\thttp://obd-memorial.ru/html/info.htm?id=%s\n",
        $person[B_SURNAME],
        $person[B_NAME],
        $person[B_PATRONYMIC],
        $birthday,
        $person[B_ID]
    );
}