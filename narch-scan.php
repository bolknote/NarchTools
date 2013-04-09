<?
    $URL = 'http://www.archive.gov.tatarstan.ru/_go/anonymous/metbooks/';
    $COOKIE = '';

    // парсим страницу, вынимаем оттуда все теги SELECT
    function U_getselects($url, $content = false)
    {
        if ($content === false) {
            global $COOKIE;

            $opts = array(
                'http' => array(
                    'method' => 'GET',
                    'header' => 'Cookie: ' . $COOKIE,
                ),
            );

            $content = file_get_contents($url, false, stream_context_create($opts));
            foreach ($http_response_header as $header) {
                if (preg_match('/^set-cookie:.*?(PHPSESSID=[^; ]+)/i', $header, $m)) {
                    $COOKIE = $m[1];
                    break;
                }
            }
        }

        $out = array();

        $content = iconv('cp1251', 'utf8', $content);

        if (preg_match('@<span class="bt4r">([^<>]+)</span>@si', $content, $m)) {
            echo 'Ошибка: ', $m[1], "\n";
            exit;
        }

        $mask = '@<span class="bt4b">([^<>]+)(?:<[^>]+>\s*)*?<select\s+.*?name="([^"]+)"[^>]*>(.*?)</select>@si';

        if (preg_match_all($mask, $content, $matches, PREG_SET_ORDER)) {

            foreach ($matches as $select) {
                $options = preg_match_all('/"([^"]+)"/s', $select[3], $m) ? $m[1] : array();

                $out[] = array(
                    'title' => $select[1],
                    'name' => $select[2],
                    'options' => $options,
                );
            }
        }

        return $out;
    }


    // Даём пользователю выбрать какой-то пункт из найденного SELECTа
    function U_reactselect($select)
    {
        for (;;) {
            echo $select['title'], "\n";

            foreach ($select['options'] as $key => $val) {
                if (strpos($val, '/') !== false) list(,$val) = explode('/', $val, 2);

                printf("% 2d. %s\n", $key + 1, $val);
            }

            echo "\nПожалуйста, введите номер пункта и нажмите Enter: ";

            $value = (int) fgets(STDIN);

            if (in_array($value - 1, array_keys($select['options']))) {
                echo "\n";
                return $select['options'][$value - 1];
            }

            echo "\n\nТакого пункта нет в списке. Попытайтесь ещё раз, пожалуйста.\n\n";
        }
    }

    // Отправляем запрос
    function U_postselect($url, $fields)
    {
        global $COOKIE;

        $fields = array_map(function($item) {
            return iconv('utf8', 'cp1251', $item);
        }, $fields);

        $opts = array(
            'http' => array(
                'method'        => 'POST',
                'header'        => 'Cookie: ' . $COOKIE . "\r\n".
                                    'Content-type: application/x-www-form-urlencoded',
                'content'       => http_build_query($fields),
            ),
        );

        return file_get_contents($url, false, stream_context_create($opts));
    }

    // Просим ввести диапазон
    function U_getyear($message)
    {
        echo $message;
        $val = (int) fgets(STDIN);

        return min(1917, max($val, 1724));
    }

    $postcontent = false;

    for ($step = 1; $step < 3; $step++) {

        $choices = array();

        foreach (U_getselects($URL, $postcontent) as $select) {
            $choices[$select['name']] = U_reactselect($select);
        }

        $choices['action'] = 'step' . $step;
        $choices['to_step' . ($step + 1)] = 'Далее >';

        $postcontent = U_postselect($URL, $choices);
    }

    $choices = array(
        'to_step4' => 'Найти',
        'action' => 'step3',
    );

    foreach (U_getselects($URL, $postcontent) as $select) {
        $choices[$select['name']] = U_reactselect($select);
    }

    $from = U_getyear('Введите начальный год: ');
    $to = U_getyear('И конечный: ');

    if ($from > $to) {
        list($from, $to) = array($to, $from);
    }

    $years = array();

    echo "\n";

    for ($year = $from; $year <= $to; $year++) {
        $choices['year'] = $year;

        $postcontent = U_postselect($URL, $choices);
        $found =
            strpos($postcontent, "\xed\xe5\x20\xed\xe0\xe9\xe4\xe5\xed\xfb") === false &&
            strpos($postcontent, "\xed\xe0\xe9\xe4\xe5\xed\xfb") !== false;

        if ($found) {
            $years[] = $year;
            echo '+';
        } else {
            echo '-';
        }

    }

    if ($years) {
        $years[] = INF;

        echo "\nАрхив найден за следующие годы: ";

        for ($i = 0, $l = sizeof($years); $i<$l-1; $i++) {
            if ($i) echo ', ';
            echo $years[$i];

            for($c = 0, $i++; $i<$l; $i++, $c++) {
                if ($years[$i] - $years[$i-1] > 1) {
                    $i--;

                    if ($c) {
                        echo '-', $years[$i];
                    }

                    break;
                }
            }
        }

        echo "\n";

    } else {
        echo "\nНичего не найдено.\n";
    }
