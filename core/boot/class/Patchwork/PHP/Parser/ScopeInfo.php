<?php /****************** vi: set fenc=utf-8 ts=4 sw=4 et: *****************
 *
 *   Copyright : (C) 2011 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/lgpl.txt GNU/LGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Lesser General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/

Patchwork_PHP_Parser::createToken('T_SCOPE_OPEN', 'T_SCOPE_CLOSE');

/**
 * The ScopeInfo parser exposes scopes to dependend parsers.
 *
 * Scopes are typed as T_OPEN_TAG, T_NAMESPACE, T_FUNCTION, T_CLASS, T_INTERFACE and T_TRAIT, each
 * of these corresponding to the type of the token who opened the scope. For each scope, this
 * parser exposes this type alongside with a reference to its opening token and its parent scope.
 *
 * ScopeInfo also manages two special token types:
 * - T_SCOPE_OPEN can be registered by dependend parsers and is emitted on scope opening tokens
 * - T_SCOPE_CLOSE matches scope closing tokens when registered within their corresponding T_SCOPE_OPEN.
 *
 * ScopeInfo eventually inherits removeNsPrefix(), namespace, nsResolved, nsPrefix properties from NamespaceInfo.
 */
class Patchwork_PHP_Parser_ScopeInfo extends Patchwork_PHP_Parser
{
    protected

    $curly     = 0,
    $scope     = false,
    $scopes    = array(),
    $nextScope = T_OPEN_TAG,
    $callbacks = array(
        'tagFirstScope' => array(T_OPEN_TAG, ';', '{'),
        'tagScopeClose' => array(T_ENDPHP, '}'),
        'tagNamespace'  => T_NAMESPACE,
        'tagFunction'   => T_FUNCTION,
        'tagClass'      => array(T_CLASS, T_INTERFACE, T_TRAIT),
    ),
    $dependencies = array(
        'NamespaceInfo' => array('namespace', 'nsResolved', 'nsPrefix'),
        'Normalizer',
    );


    function removeNsPrefix()
    {
        empty($this->nsPrefix) || $this->dependencies['NamespaceInfo']->removeNsPrefix();
    }

    protected function tagFirstScope(&$token)
    {
        $t = $this->getNextToken();

        if (T_NAMESPACE === $t[0] || T_DECLARE === $t[0]) return;

        $this->unregister(array(__FUNCTION__ => array(T_OPEN_TAG, ';', '{')));
        $this->  register(array('tagScopeOpen'  => '{'));

        return $this->tagScopeOpen($token);
    }

    protected function tagScopeOpen(&$token)
    {
        if ($this->nextScope)
        {
            $this->scope = (object) array(
                'parent' => $this->scope,
                'type'   => $this->nextScope,
                'token'  => &$token,
            );

            $this->nextScope = false;
            $this->scopes[] = array($this->curly, array());
            $this->curly = 0;

            if (isset($this->tokenRegistry[T_SCOPE_OPEN]))
            {
                unset($this->tokenRegistry[T_SCOPE_CLOSE]);
                $this->unshiftTokens(array(T_WHITESPACE, ''));
                $this->register(array('tagAfterScopeOpen' => T_WHITESPACE));
                return T_SCOPE_OPEN;
            }
        }
        else ++$this->curly;
    }

    protected function tagAfterScopeOpen(&$token)
    {
        $this->unregister(array(__FUNCTION__ => T_WHITESPACE));

        if (empty($this->tokenRegistry[T_SCOPE_CLOSE])) return;

        $this->scopes[count($this->scopes) - 1][1] = $this->tokenRegistry[T_SCOPE_CLOSE];
        unset($this->tokenRegistry[T_SCOPE_CLOSE]);
    }

    protected function tagScopeClose(&$token)
    {
        if (0 > --$this->curly && $this->scopes)
        {
            list($this->curly, $c) = array_pop($this->scopes);

            if ($c)
            {
                $this->tokenRegistry[T_SCOPE_CLOSE] = array_reverse($c);
                $this->unshiftTokens(array(T_WHITESPACE, ''));
                $this->register(array('tagAfterScopeClose' => T_WHITESPACE));
                return T_SCOPE_CLOSE;
            }

            $this->scope = $this->scope->parent;
        }
    }

    protected function tagAfterScopeClose(&$token)
    {
        $this->unregister(array(__FUNCTION__ => T_WHITESPACE));
        unset($this->tokenRegistry[T_SCOPE_CLOSE]);
        $this->scope = $this->scope->parent;
    }

    protected function tagClass(&$token)
    {
        $this->nextScope = $token[0];
    }

    protected function tagFunction(&$token)
    {
        $this->nextScope = T_FUNCTION;
        $this->register(array('tagSemiColon'  => ';')); // For abstracts methods
    }

    protected function tagNamespace(&$token)
    {
        switch ($this->lastType)
        {
        default: return;
        case ';':
        case '}':
        case T_OPEN_TAG:
            $t = $this->getNextToken();
            if (T_STRING === $t[0] || '{' === $t[0])
            {
                $this->nextScope = T_NAMESPACE;

                if ($this->scope)
                {
                    $this->  register(array('tagFirstScope' => array(';', '{')));
                    $this->unregister(array('tagScopeOpen'  => '{'));
                    return $this->tagScopeClose($token);
                }
            }
        }
    }

    protected function tagSemiColon(&$token)
    {
        $this->unregister(array(__FUNCTION__ => ';'));
        $this->nextScope = false;
    }
}
