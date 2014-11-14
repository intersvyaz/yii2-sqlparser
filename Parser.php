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
        if (substr($sql, -4) === '.sql')
            $this->sql = file_get_contents($sql);
        else
            $this->sql = $sql;

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
                if (isset($value[0]) && is_array($value[0])) {
                    foreach ($value[0] as $valKey => $valVal)
                        $newParams[$key . '_' . $valKey] = $valVal;
                } elseif (!isset($value['bind']) || $value['bind'] === true) {
                    if (isset($value[0]) && isset($value[1]))
                        $newParams[$key] = [$value[0], $value[1]];
                    elseif (isset($value[0]))
                        $newParams[$key] = $value[0];
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
        $paramName = ltrim($paramName, ':');
        if (array_key_exists($paramName, $this->params)) {
            $paramValue = $this->params[$paramName];
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
            }
        } else {
            $queryInComment = '';
        }

        $this->sql = str_replace($comment, $queryInComment, $this->sql);
    }
}
