<?php
namespace sqlparserunit;

use Intersvyaz\SqlParser\Parser;

class SqlParserTest extends TestCase
{
    public function sqlData()
    {
        $query = '/*param1 sql1 */
                /*param2 sql2 */
                --*param3 sql3
                --*param6 --*param7 sql7
                /*param4 --*param5 sql5 */
                /*param8 --*param9 --*param10 sql10 */
                --*param11 :@param11
                --*param12|param13 test multiple
                /*param14|param15 test multiple*/
                /*PARAM16 case-insensitive*/
                --*param17 case-insensitive2
                --*PARAM18 case-insensitive3
                /*param19 :@param19*/
                /*param20 :@param20*/
        ';

        return [
            [$query, ['param1' => 'v1'], '/^\s*sql1\s*$/'],
            [$query, [':param1' => 'v1'], '/^\s*sql1\s*$/'],
            [$query, ['param1' => 'v1', 'param2' => ['v2', \PDO::PARAM_STR]], '/^\s*sql1\s*sql2\s*$/'],
            [$query, ['param6' => 'v6'], '/^\s*$/'],
            [$query, ['param6' => 'v6', 'param7' => 'v7'], '/^\s*sql7\s*$/'],
            [$query, ['param4' => 'v4', 'param5' => 'v5'], '/^\s*sql5\s*$/'],
            [$query, ['param8' => 'v8', 'param9' => 'v9', 'param10' => 'v10'], '/^\s*sql10\s*$/'],
            ["sql1\n\n\n\n\nsql2", ['param1' => 'v1'], '/^sql1\nsql2$/'],
            ["sql1", [], '/^sql1$/'],
            ["-- test\n" . $query, [], '/^-- test$/'],
            [$query, ['param11' => [['v1', 'v2']]], "/^:param11_0,:param11_1$/"],
            ["--*param order by param", ['param' => ['v1', 'bind' => 'text']], "/^order by v1$/"],
            [":@paraM", ['paRam' => [[1,2]]], "/^:paRam_0,:paRam_1$/"],
            ["--*param sql1\n--*parAM :@paraM", ['Param' => [[1,2]]], "/^\s*sql1\n :Param_0,:Param_1$/"],
            [$query, ['param12' => 'test'], "/^test multiple$/"],
            [$query, ['param14' => 'test'], "/^test multiple$/"],
            [$query, ['PARAM17' => 'test'], "/^case-insensitive2$/"],
            [$query, ['PARAM18' => 'test'], "/^case-insensitive3$/"],
            [$query, ['PARAM19' => [[1, 2]]], "/^:PARAM19_0,:PARAM19_1$/"],
            [$query, ['PARAM20' => ['bind' => 'tuple', [[1], [2]]]], "/^\(:PARAM20_0_0\),\(:PARAM20_1_0\)$/"],
        ];
    }

    /**
     * @dataProvider sqlData
     */
    public function testSqlParsing($query, $params, $queryPattern)
    {
        $this->assertRegExp($queryPattern, (string)(new Parser($query, $params)));
    }

    public function paramsData()
    {
        return [
            [[], []],
            [[':simpleName' => 'simpleValue'], [':simpleName' => 'simpleValue']],
            [
                [':simpleNameSimpleValueWithType' => ['simpleValue', \PDO::PARAM_STR]],
                [':simpleNameSimpleValueWithType' => ['simpleValue', \PDO::PARAM_STR]]
            ],
            [
                [':complexNameSimpleValue' => ['simpleValue', 'bind' => true]],
                [':complexNameSimpleValue' => 'simpleValue']
            ],
            [[':complexNameBindText' => ['simpleValue', 'bind' => 'text']], []],
            [[':complexNameNoBind' => ['bind' => false]], []],
            [
                ['arrayName' => [[0, 1, 2, 3]]],
                [':arrayName_0' => 0, ':arrayName_1' => 1, ':arrayName_2' => 2, ':arrayName_3' => 3]
            ],
        ];
    }

    /**
     * @dataProvider paramsData
     */
    public function testSimplifyParams($params, $simplifiedParams)
    {
        $parser = new Parser('', $params);
        $this->assertEquals($simplifiedParams, $parser->getSimplifiedParams());
    }
}
