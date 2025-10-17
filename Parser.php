<?php

namespace Intersvyaz\SqlParser;

/**
 * Разбор текста запроса.
 * Получение текста по имени файла sql, парсинг специальных комментариев с именами переменных,
 * замена комментариев-массивов на строку переменных с уникальными именами
 */
class Parser
{
    /** @var string Текст sql запроса, который надо преобразовать, либо имя файла с запросом */
    private $sql;
    /** @var array Параметры, влияющие на парсинг sql запроса */
    private $params;
    /** @var array "Упрощённый" список параметров (для кеширования) */
    private $simplifiedParams;

    /**
     * @param string $sql
     * @param array $params
     */
    public function __construct($sql, $params = [])
    {
        if (substr($sql, -4) === '.sql') {
            $this->sql = file_get_contents($sql);
        } else {
            $this->sql = $sql;
        }

        $this->params = $params;
        $this->parseSql();
    }

    /**
     * Готовый sql запрос
     * @return string
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * @inheritdoc
     */
    public function __toString()
    {
        return $this->getSql();
    }

    /**
     * "Упрощённый" список параметров
     * @return array
     */
    public function getSimplifiedParams()
    {
        if (!isset($this->simplifiedParams)) {
            $this->simplifiedParams = $this->simplifyParams($this->params);
        }

        return $this->simplifiedParams;
    }

    /**
     * Конвертирует параметры запроса из расширенного формата в параметры пригодные для \yii\db\Command::bindValues.
     * @param array $params Параметры построения запроса.
     * @return array
     */
    private function simplifyParams(array $params)
    {
        if (empty($params)) {
            return $params;
        }

        $newParams = [];
        foreach ($params as $key => $value) {
            $key = ':' . ltrim($key, ':');

            if (is_array($value)) {
                if (!isset($value['bind'])) {
                    $value['bind'] = true;
                }

                if ($value['bind'] === true) {
                    if (isset($value[0]) && isset($value[1])) {
                        $newParams[$key] = [$value[0], $value[1]];
                    } elseif (isset($value[0])) {
                        if (is_array($value[0])) {
                            foreach ($value[0] as $valKey => $valVal) {
                                $newParams[$key . '_' . $valKey] = $valVal;
                            }
                        } else {
                            $newParams[$key] = $value[0];
                        }
                    }
                } elseif ($value['bind'] === 'tuple') {
                    if (isset($value[0]) && is_array($value[0])) {
                        // Скинем индексы
                        $value[0] = array_values($value[0]);

                        foreach ($value[0] as $valKey => $valVal) {
                            if (is_array($valVal)) {
                                foreach ($valVal as $k => $v) {
                                    $newParams[$key . '_' . $valKey . '_' . $k] = $v;
                                }
                            } else {
                                $newParams[$key . '_' . $valKey] = $valVal;
                            }
                        }
                    } elseif (isset($value[0])) {
                        $newParams[$key] = $value[0];
                    }
                } elseif (isset($value[0]) && is_array($value[0])) {
                    foreach ($value[0] as $valKey => $valVal) {
                        $newParams[$key . '_' . $valKey] = $valVal;
                    }
                }
            } else {
                $newParams[$key] = $value;
            }
        }
        return $newParams;
    }

    /**
     * Функция разбора и подготовки текста sql запроса.
     */
    private function parseSql()
    {
        $matches = null;

        // Разбор многострочных комментариев
        // ВАЖНО: "(.*?)" а не "(.+?)" на случай, если просто написали код такого типа:
        // WHEN 700 /*float4*/ THEN 24 /*FLT_MANT_DIG*/
        // В случае "+" как комментарий будет распознана строка, включающая код:
        // "*/ THEN 24 /*FLT_MANT_DIG"
        // который в итоге пропадет потом из результирующей строки запроса.
        if (preg_match_all('#/\*([\w|]+)(.*?)\*/#s', $this->sql, $matches)) {
            $count = count($matches[0]);

            for ($i = 0; $i < $count; $i++) {
                $this->replaceComment($matches[0][$i], $matches[2][$i], $matches[1][$i]);
            }
        }

        // Многоитерационный разбор однострочных комментариев
        while (true) {
            if (preg_match_all('#--\*([\w|]+)(.+)#', $this->sql, $matches)) {
                $count = count($matches[0]);

                for ($i = 0; $i < $count; $i++) {
                    $this->replaceComment($matches[0][$i], $matches[2][$i], $matches[1][$i]);
                }
            } else {
                break;
            }
        }

        // Разбор переменных-массивов, которые находились изначально вне комментариев
        if (preg_match_all('#:@(\w+)#', $this->sql, $matches)) {
            $count = count($matches[0]);

            for ($i = 0; $i < $count; $i++) {
                $this->replaceComment($matches[0][$i], $matches[0][$i], $matches[1][$i], false);
            }
        }

        $this->sql = preg_replace("/\n+/", "\n", trim($this->sql));
    }

    /**
     * Заменяем комментарий или некоторую другую подстроку в запросе на соответствующе преобразованный блок или удаляем,
     * если указан соответствующий параметр (делается по умолчанию - для комментариев).
     * Используется также для замены параметра-массива - :@<param_name> не помещенного в комментарий, но только если
     * такой параметр есть в массиве параметров. Отдельную функцию делать не стали, потому что функционал одинаковый.
     * Либо можно переименовать функцию.
     * @param string $comment Заменямый комментарий.
     * @param string $queryInComment Текст внутри комментария.
     * @param string $paramName Имя параметра.
     * @param boolean $replaceNotFoundParam заменять ли комментарий, если не нашли соответствующего параметра в списке
     */
    private function replaceComment($comment, $queryInComment, $paramName, $replaceNotFoundParam = true)
    {
        $param = $this->getParam($paramName);

        if (strpos($paramName, '|')) {
            $found = false;

            foreach (explode('|', $paramName) as $param) {
                if (array_key_exists($param, $this->params)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $queryInComment = '';
            }
        } elseif ($param) {
            $paramName = $param[0];
            $paramValue = $param[1];

            if (is_array($paramValue)) {
                $value = isset($paramValue[0]) ? $paramValue[0] : null;
                $bind = isset($paramValue['bind']) ? $paramValue['bind'] : true;
            } else {
                $value = $paramValue;
                $bind = true;
            }

            if ($bind === true && is_array($value)) {
                $valArr = [];

                foreach (array_keys($value) as $keyVal) {
                    $valArr[] = ':' . $paramName . '_' . $keyVal;
                }

                $replacement = implode(',', $valArr);
                $queryInComment = preg_replace('/:@' . preg_quote($paramName) . '/i', $replacement, $queryInComment);
            } elseif ($bind === 'text') {
                $queryInComment = preg_replace('/' . preg_quote($paramName) . '/i', $value, $queryInComment);
            } elseif ($bind === 'tuple') {
                if (is_array($paramValue[0])) {
                    $replacements = [];
                    // Скинем индексы
                    $paramValue[0] = array_values($paramValue[0]);

                    foreach ($paramValue[0] as $keyParam => $val) {
                        $name = ':' . $paramName . '_' . $keyParam;
                        if (is_array($val)) {
                            $valArr = [];

                            foreach (array_keys($val) as $keyVal) {
                                $valArr[] = $name . '_' . $keyVal;
                            }

                            $valName = implode(',', $valArr);
                        } else {
                            $valName = $name;
                        }

                        $replacements[] = '(' . $valName . ')';
                    }

                    $replacement = implode(',', $replacements);
                } else {
                    $replacement = $paramValue;
                }

                $queryInComment = preg_replace('/:@' . preg_quote($paramName) . '/i', $replacement, $queryInComment);
            }
        } elseif ($replaceNotFoundParam) {
            $queryInComment = '';
        }

        $this->sql = str_replace($comment, $queryInComment, $this->sql);
    }

    /**
     * Ищет параметр в массиве $this->params
     * @param string $name имя параметра
     * @return array|bool массив ['имя_параметра_без_ведущего_двоеточия', 'значение_параметра'] или ложь, если параметра
     *     нет
     */
    private function getParam($name)
    {
        $name = $outName = mb_strtolower(ltrim($name, ':'));

        // Формируем имя параметра на выход точно такое же, какое и забиндено в параметры.
        foreach ($this->params as $key => $value) {
            $key = ltrim($key, ':');

            if (mb_strtolower($key) == $name) {
                $outName = $key;
                break;
            }
        }

        $params = array_change_key_case($this->params, CASE_LOWER);

        if (array_key_exists($name, $params)) {
            return [$outName, $params[$name]];
        } elseif (array_key_exists(':' . $name, $params)) {
            return [$outName, $params[':' . $name]];
        }

        return false;
    }
}
