<?php
namespace Intersvyaz\SqlParser;

class Parser
{

    /**
     * @var string Текст sql запроса, который надо преобразовать
     */
    private $sql;
    /**
     * @var array параметры, влияющие на парсинг sql запроса
     */
    private $params;
    /**
     * @var array "упрощённый" список параметров, для кеширования
     */
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
     * @return string готовый sql запрос
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
     * @return array "упрощённый" список параметров
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
    private function simplifyParams($params)
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
                        //скинем индексы
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
        // Разбор многострочных комментариев
        if (preg_match_all('#/\*(\w+)(.+?)\*/#s', $this->sql, $matches)) {
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $this->replaceComment($matches[0][$i], $matches[2][$i], $matches[1][$i]);
            }
        }

        // Многоитерационный разбор однострчных комментариев
        while (true) {
            if (preg_match_all('#--\*(\w+)(.+)#', $this->sql, $matches)) {
                $count = count($matches[0]);
                for ($i = 0; $i < $count; $i++) {
                    $this->replaceComment($matches[0][$i], $matches[2][$i], $matches[1][$i]);
                }
            } else {
                break;
            }
        }

        $this->sql = preg_replace("/\n+/", "\n", trim($this->sql));
    }

    /**
     * Заменяем коментарий в запросе на соответствующе преобразованный блок или удаляем.
     * @param string $comment Заменямый комментарий.
     * @param string $queryInComment Текст внутри комментария.
     * @param string $paramName Имя параметра.
     */
    private function replaceComment($comment, $queryInComment, $paramName)
    {
        $param = $this->getParam($paramName);
        if ($param) {
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
                $queryInComment = preg_replace('/:@' . preg_quote($paramName) . '/', $replacement, $queryInComment);
            } elseif ($bind === 'text') {
                $queryInComment = preg_replace('/' . preg_quote($paramName) . '/', $value, $queryInComment);
            } elseif ($bind === 'tuple') {
                if (is_array($paramValue[0])) {
                    $replacements = [];
                    //скинем индексы
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
                $queryInComment = preg_replace('/:@' . preg_quote($paramName) . '/', $replacement, $queryInComment);
            }
        } else {
            $queryInComment = '';
        }

        $this->sql = str_replace($comment, $queryInComment, $this->sql);
    }

    /**
     * Ищет параметр в массиве $this->params
     * @param string $name имя параметра
     * @return array|bool массив ['имя_параметра_без_ведущего_двоеточия', 'значение_параметра'] или ложь если параметра
     *     нет
     */
    private function getParam($name)
    {
        $name = ltrim($name, ':');

        if (array_key_exists($name, $this->params)) {
            return [$name, $this->params[$name]];
        } elseif (array_key_exists(':' . $name, $this->params)) {
            return [$name, $this->params[':' . $name]];
        }

        return false;
    }
}
