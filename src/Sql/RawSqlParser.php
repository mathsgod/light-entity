<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

/** Lexes SQL literals/comments before validating and renaming parameter markers. */
final class RawSqlParser
{
    public function compile(RawSqlSourceExpression|RawSqlPredicateExpression|RawSqlValueExpression $source, callable $bind, SqlDialect $dialect): string
    {
        $sql = $source->sql;
        $fragment = !($source instanceof RawSqlSourceExpression);
        $positional = array_is_list($source->parameters);
        $parameters = [];
        foreach ($source->parameters as $name => $value) {
            if (!$positional) {
                if (!is_string($name) || !preg_match('/^:?[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                    throw new \InvalidArgumentException('Use a positional parameter list or a named parameter map.');
                }
                $name = ltrim($name, ':');
                if (array_key_exists($name, $parameters)) { throw new \InvalidArgumentException('Duplicate raw SQL parameter name.'); }
            }
            $parameters[$name] = $value;
        }
        $used = [];
        $index = 0;
        $depth = 0;
        $started = false;
        $output = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) { $output .= $char; ++$i; continue; }
            if ((substr($sql, $i, 2) === '--' && (!$dialect instanceof MySqlDialect
                || $i + 2 >= $length || ctype_space($sql[$i + 2])))
                || ($char === '#' && $dialect instanceof MySqlDialect)) {
                $end = strcspn($sql, "\r\n", $i) + $i;
                $output .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                if ($dialect instanceof MySqlDialect && preg_match('/\G\/\*(?:!|M!)/i', $sql, $match, 0, $i)) {
                    throw new \InvalidArgumentException('Executable SQL comments are not supported in raw sources.');
                }
                $start = $i;
                $i += 2;
                $comments = 1;
                while ($i < $length && $comments > 0) {
                    if ($dialect instanceof PostgreSqlDialect && substr($sql, $i, 2) === '/*') { ++$comments; $i += 2; }
                    elseif (substr($sql, $i, 2) === '*/') { --$comments; $i += 2; }
                    else { ++$i; }
                }
                if ($comments !== 0) { throw new \InvalidArgumentException('Unterminated SQL comment.'); }
                $output .= substr($sql, $start, $i - $start);
                continue;
            }
            if (!$started) {
                if (!$fragment && !preg_match('/\GSELECT\b/i', $sql, $match, 0, $i)) {
                    throw new \InvalidArgumentException('Raw SQL must start with a single composable SELECT.');
                }
                $started = true;
            }
            if (in_array($char, ["'", '"', '`'], true)
                || ($char === '[' && ($dialect instanceof SqlServerDialect || $dialect instanceof SqliteDialect))) {
                $start = $i++;
                $close = $char === '[' ? ']' : $char;
                $backslash = $dialect instanceof MySqlDialect || ($dialect instanceof PostgreSqlDialect
                    && $char === "'" && $start > 0 && in_array($sql[$start - 1], ['e', 'E'], true));
                $closed = false;
                while ($i < $length) {
                    if ($backslash && $sql[$i] === '\\') { $i += 2; continue; }
                    if ($sql[$i] === $close) {
                        if (($sql[$i + 1] ?? '') === $close) { $i += 2; continue; }
                        ++$i; $closed = true; break;
                    }
                    ++$i;
                }
                if (!$closed) { throw new \InvalidArgumentException('Unterminated quoted SQL value or identifier.'); }
                $output .= substr($sql, $start, $i - $start);
                continue;
            }
            if ($char === '$' && $dialect instanceof PostgreSqlDialect
                && preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i)) {
                $end = strpos($sql, $match[0], $i + strlen($match[0]));
                if ($end === false) { throw new \InvalidArgumentException('Unterminated dollar-quoted SQL value.'); }
                $end += strlen($match[0]);
                $output .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($char === ';') { throw new \InvalidArgumentException('Raw SELECT sources cannot contain statement separators, including a trailing semicolon.'); }
            if ($char === '(') { ++$depth; }
            if ($char === ')' && --$depth < 0) { throw new \InvalidArgumentException('Unbalanced raw SQL parentheses.'); }
            if ($char === '?') {
                if (!$positional || !array_key_exists($index, $parameters)) {
                    throw new \InvalidArgumentException('Missing positional parameter or mixed SQL parameter styles.');
                }
                $output .= $bind($parameters[$index]);
                $used[$index++] = true;
                ++$i;
                continue;
            }
            if (substr($sql, $i, 2) === '::') { $output .= '::'; $i += 2; continue; }
            if ($char === ':' && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $i)) {
                $name = $match[1];
                if ($positional || !array_key_exists($name, $parameters)) {
                    throw new \InvalidArgumentException("Missing named parameter or mixed SQL parameter styles: {$name}");
                }
                // Repeated names receive separate placeholders for native PDO drivers.
                $output .= $bind($parameters[$name]);
                $used[$name] = true;
                $i += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $match, 0, $i)) {
                if (strtoupper($match[0]) === 'INTO') {
                    throw new \InvalidArgumentException('SELECT INTO is not a composable raw source.');
                }
                $output .= $match[0];
                $i += strlen($match[0]);
                continue;
            }
            $output .= $char;
            ++$i;
        }
        if (!$started || $depth !== 0) { throw new \InvalidArgumentException('Expected a complete, balanced SELECT source.'); }
        if (count($used) !== count($parameters)) { throw new \InvalidArgumentException('Unused or excess raw SQL parameters.'); }
        return $output;
    }
}
