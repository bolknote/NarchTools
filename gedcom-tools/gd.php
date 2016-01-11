<?php
declare(strict_types=1);
namespace GDTree;

class Exception extends \Exception
{
}

class Reader
{
	// чтение построчно из файла
    private static function reader(string $filename):\Generator
    {
        $fp = @fopen($filename, 'rb');
        if ($fp === false) {
            throw new Exception("File '$filename' is not found");
        }

        try {
            while (!feof($fp)) {
                yield rtrim(fgets($fp));
            }
        } finally {
            fclose($fp);
        }
    }

    // разбор файла GEDCOM
    public static function parse(string $filename):array
    {
        // Индикатор, что дальше пойдёт блок первого уровня
        $block1 = false;
        // Индикаторы, что идёт разбор семьи или персоны
        $fid = $pid = null;
        // Массивы семей и персон
        $families = $persons = [];

        foreach (self::reader($filename) as $line) {
            switch (true) {
                // встретилось начало блока персоны
                case preg_match('/^0 (\S+) INDI/sS', $line, $m):
                    $persons[$pid = $m[1]] = [];
                    break;

                // встретилось начало блока семьи
                case preg_match('/^0 (\S+) FAM/sS', $line, $m):
                    $families[$fid = $m[1]] = [];
                    break;

                // встретилось указание на дату рождения или смерти
                case $pid !== null && in_array($block1, ['BIRT', 'DEAT Y'], true) &&
                preg_match('/^2 DATE (.+)/sS', $line, $m):
                    $persons[$pid][$block1] = preg_match('/\d{4}$/', $m[1], $yy) ? $yy[0] : $m[1];
                    break;

                // данные персоны
                case $pid !== null && preg_match('/^[12] (GIVN|SURN|FAMC|SEX) (.+)/sS', $line, $m):
                    $persons[$pid][$m[1]] = $m[2];
                    break;

                // указано, что человек умер
                case $pid !== null && $line == '1 DEAT Y':
                	$persons[$pid]['DEAT Y'] = '?';
                	$block1 = 'DEAT Y';
                	break;

                // данные семьи
                case $fid !== null && preg_match('/^1 (HUSB|WIFE) (.+)/sS', $line, $m):
                    $families[$fid][$m[1]] = $m[2];
                    break;

            	// начался незнакомый блок нулевого уровня
            	case strpos($line, '0 ') === 0:
            		$block1 = false;
            		$fid = $pid = null;
            		break;

                // начался блок первого уровня
				case strpos($line, '1 ') === 0:
					$block1 = substr($line, 2);
					break;
            }
        }

        // В персоны добавляем родительскую семью, вместо её идентификатора
        array_walk($persons, function (array &$person) use (&$families) {
            if (isset($person['FAMC'])) {
                $person['FAMC'] = &$families[$person['FAMC']];
            }
        });

        // Вклеиваем в семьи их участников вместо номидентификаторов
        array_walk($families, function (array &$family) use (&$persons) {
            foreach ($family as $id => $member) {
                $family[$id] = &$persons[$member];
            }
        });

        return $persons;
    }

    // Массив персоны из сырой информации
    private static function person(array $p, string $sex = null):array
    {
        $person = [];

        foreach (['GIVN', 'SURN', 'BIRT'] as $f) {
            $person[strtolower($f)] = $p[$f] ?? '?';
        }

        $person['sex'] = $p['SEX'] ?? $sex ?? '?';
        $person['deat'] = $p['DEAT Y'] ?? null;

        return $person;
    }

    // вытягивание цепочек семей из дерева
    private static function familyFlat(array $p1, string $s1 = null, array $p2 = null, string $s2 = null):array
    {
        $root = $p2 !== null ? [[
            self::person($p1, $s1),
            self::person($p2, $s2),
        ]] : [[
	        self::person($p1),
        ]];

    	if (isset($p1['FAMC'])) {
	    	$chains = [];

    		$h = $p1['FAMC']['HUSB'] ?? [];
    		$w = $p1['FAMC']['WIFE'] ?? [];
			$fs = array_merge(
				$h ? self::familyFlat($h, 'M', $w, 'F') : [],
				$w ? self::familyFlat($w, 'F', $h, 'M') : []
			);

        	foreach ($fs as $chain) {
        		$chains[] = array_merge($root, $chain);
        	}

        	return $chains;
        } else {
        	return [$root];
        }

        return $chains;
    }

	// вытягивание цепочек семей из дерева (входная точка)
    public static function flat(array $tree):array
    {
    	return self::familyFlat($tree);
    }

    // отображение цепочек в HTML
    public static function render(array $chains):string
    {
    	usort($chains, function (array $f1, array $f2) {
            return count($f2) <=> count($f1) ?: strcmp(end($f2)[0]['givn'], end($f1)[0]['givn']);
    	});

    	$out = '';
    	foreach ($chains as $chain) {
    		$out .= "<chain>";
    		$end = end($chain)[0];

    		foreach ($chain as $i => $family) {
    			$out .= "<family>";

    			foreach ($family as $p) {
    				$bd = $p['deat'] === null ? $p['birt'] : "{$p['birt']}—{$p['deat']}";
    				$out .= "<card sex='{$p['sex']}'>{$p['givn']} <bd>$bd</bd></card>";
    			}

    			if ($i === 0) {
    				$out .= "<card sex='?'>{$end['surn']}</card>";
    			}

    			$out .= "</family>";
    		}

    		$out .= "</chain>";
    	}

    	return $out;
    }
}

if ($_SERVER['argc'] > 2) {
	list(,$file,$root) = $_SERVER['argv'];

	$OUT = Reader::render(Reader::flat(Reader::parse($file)[$root]));
	require 'out.template.php';
} else {
	echo "Run: php {$_SERVER['argv'][0]} <gedcomfile> <personID>\n";
}