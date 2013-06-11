<?php

/**
 * Hoa
 *
 *
 * @license
 *
 * New BSD License
 *
 * Copyright © 2007-2013, Ivan Enderlin. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Hoa nor the names of its contributors may be
 *       used to endorse or promote products derived from this software without
 *       specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDERS AND CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace {

from('Hoa')

/**
 * \Hoa\Praspel\Exception\Generic
 */
-> import('Praspel.Exception.Generic')

/**
 * \Hoa\Praspel\Exception\Group
 */
-> import('Praspel.Exception.Group')

/**
 * \Hoa\Praspel\Exception\Failure\Precondition
 */
-> import('Praspel.Exception.Failure.Precondition')

/**
 * \Hoa\Praspel\Exception\Failure\Postcondition
 */
-> import('Praspel.Exception.Failure.Postcondition')

/**
 * \Hoa\Praspel\Exception\Failure\Exceptional
 */
-> import('Praspel.Exception.Failure.Exceptional')

/**
 * \Hoa\Praspel\Exception\Failure\Invariant
 */
-> import('Praspel.Exception.Failure.Invariant')

/**
 * \Hoa\Praspel\Exception\Failure\InternalPrecondition
 */
-> import('Praspel.Exception.Failure.InternalPrecondition')

/**
 * \Hoa\Praspel\Visitor\Interpreter
 */
-> import('Praspel.Visitor.Interpreter')

/**
 * \Hoa\Praspel\Visitor\Praspel
 */
-> import('Praspel.Visitor.Praspel')

/**
 * \Hoa\Compiler\Llk
 */
-> import('Compiler.Llk.~')

/**
 * \Hoa\File\Read
 */
-> import('File.Read');

}

namespace Hoa\Praspel {

/**
 * Class \Hoa\Praspel\Praspel.
 *
 * Take a specification + data and validate/verify a callable.
 *
 * @author     Ivan Enderlin <ivan.enderlin@hoa-project.net>
 * @copyright  Copyright © 2007-2013 Ivan Enderlin.
 * @license    New BSD License
 */

class Praspel {

    /**
     * Specification.
     *
     * @var \Hoa\Praspel\Model\Specification object
     */
    protected $_specification  = null;

    /**
     * Data of the specification.
     *
     * @var \Hoa\Praspel array
     */
    protected $_data           = null;

    /**
     * Callable to validate and verify.
     *
     * @var \Hoa\Core\Consistency\Xcallable object
     */
    protected $_callable       = null;

    /**
     * Visitor Praspel.
     *
     * @var \Hoa\Praspel\Visitor\Praspel object
     */
    protected $_visitorPraspel = null;



    /**
     * Construct.
     *
     * @access  public
     * @param   \Hoa\Praspel\Model\Specification  $specification    Specification.
     * @param   \Hoa\Core\Consistency\Xcallable   $callable         Callable.
     * @return  void
     */
    public function __construct ( Model\Specification             $specification,
                                  \Hoa\Core\Consistency\Xcallable $callable ) {

        $this->setSpecification($specification);
        $this->setCallable($callable);

        return;
    }

    /**
     * Runtime assertion checker.
     *
     * @access  public
     * @return  bool
     * @throw   \Hoa\Praspel\Exception\Generic
     * @throw   \Hoa\Praspel\Exception\Group
     */
    public function evaluate ( ) {

        // Start.
        $callable      = $this->getCallable();
        $reflection    = $callable->getReflection();
        $specification = $this->getSpecification();
        $exceptions    = new \Hoa\Praspel\Exception\Group(
            'The Runtime Assertion Checker has detected errors for %s.',
            0, $callable);

        if($reflection instanceof \ReflectionMethod)
            $reflection->setAccessible(true);

        // Prepare data.
        if(null === $data = $this->getData())
            throw new Exception\Generic(
                'No data were given. The System Under Test %s needs data to ' .
                'be executed.', 1, $callable);

        $arguments = array();

        foreach($reflection->getParameters() as $parameter) {

            $name = $parameter->getName();

            if(true === array_key_exists($name, $data)) {

                $arguments[$name] = &$data[$name];
                continue;
            }

            if(false === $parameter->isOptional())
                // Let the error be caught by the @requires clause.
                continue;

            $arguments[$name] = $parameter->getDefaultValue();
        }

        // Check precondition.
        $precondition = true;

        if(true === $specification->clauseExists('requires')) {

            $requires     = $specification->getClause('requires');
            $precondition = $this->checkClause(
                $requires,
                $arguments,
                $exceptions,
                __NAMESPACE__ . '\Exception\Failure\Precondition'
            );
        }

        if(0 < count($exceptions))
            throw $exceptions;

        try {

            // Invoke.
            if($reflection instanceof \ReflectionFunction)
                $return = $reflection->invokeArgs($arguments);
            else {

                $_callback = $callable->getValidCallback();
                $_object   = $_callback[0];
                $return    = $reflection->invokeArgs($_object, $arguments);
            }

            // Check normal postcondition.
            $postcondition = true;

            if(true === $specification->clauseExists('ensures')) {

                $ensures              = $specification->getClause('ensures');
                $arguments['\result'] = $return;
                $postcondition        = $this->checkClause(
                    $ensures,
                    $arguments,
                    $exceptions,
                    __NAMESPACE__ . '\Exception\Failure\Postcondition'
                );
            }
        }
        catch ( \Exception $exception ) {

            // Check exceptional postcondition.
            $postcondition = true;

            if(true === $specification->clauseExists('throwable')) {

                $throwable            = $specification->getClause('throwable');
                $arguments['\result'] = $exception;
                $postcondition        = $this->checkExceptionalClause(
                    $throwable,
                    $arguments,
                    $exceptions,
                    __NAMESPACE__ . '\Exception\Failure\Exceptional'
                );
            }
        }

        if(0 < count($exceptions))
            throw $exceptions;

        // Verdict.
        return $precondition && $postcondition;
    }

    /**
     * Check a clause.
     *
     * @access  protected
     * @param   \Hoa\Praspel\Model\Declaration   $clause        Clause.
     * @param   array                           &$data          Data.
     * @param   \Hoa\Praspel\Exception\Group     $exceptions    Exceptions group.
     * @param   string                           $exception     Exception to
     *                                                          throw.
     * @return  bool
     * @throw   \Hoa\Praspel\Exception
     */
    protected function checkClause ( Model\Declaration $clause, Array &$data,
                                     Exception\Group $exceptions, $exception ) {

        $verdict = true;

        foreach($clause as $name => $variable) {

            if(false === array_key_exists($name, $data)) {

                $exceptions[] = new $exception(
                    'Variable %s has no value and is required.', 0, $name);

                continue;
            }

            $_verdict = $variable->predicate($data[$name]);

            if(false === $_verdict)
                $exceptions[] = new $exception(
                    'Variable %s does not verify the constraint %s.',
                    0,
                    array($name, $this->getVisitorPraspel()->visit($variable)));

            $verdict = $_verdict && $verdict;
        }

        return $verdict;
    }

    /**
     * Check an exceptional clause.
     *
     * @access  protected
     * @param   \Hoa\Praspel\Model\Throwable    $clause        Clause.
     * @param   array                          &$data          Data.
     * @param   \Hoa\Praspel\Exception\Group    $exceptions    Exceptions group.
     * @param   string                          $exception     Exception to
     *                                                         throw.
     * @return  bool
     * @throw   \Hoa\Praspel\Exception
     */
    protected function checkExceptionalClause ( Model\Throwable $clause,
                                                Array &$data,
                                                Exception\Group $exceptions,
                                                $exception ) {

        $verdict = false;

        foreach($clause as $identifier) {

            $_exception   = $clause[$identifier];
            $instanceName = $_exception->getInstanceName();

            if($data['\result'] instanceof $instanceName) {

                $verdict = true;
                break;
            }

            foreach((array) $_exception->getDisjunction() as $_identifier) {

                $__exception   = $clause[$_identifier];
                $_instanceName = $__exception->getInstanceName();

                if($exception instanceof $_instanceName) {

                    $verdict = true;
                    break;
                }
            }
        }

        if(false === $verdict)
            $exceptions[] = new $exception(
                'The exception %s has been thrown and it is not specified.',
                0, array(get_class($data['\result'])));

        return $verdict;
    }

    /**
     * Set specification.
     *
     * @access  protected
     * @param   \Hoa\Praspel\Model\Specification  $specification    Specification.
     * @return  \Hoa\Praspel\Model\Specification
     */
    protected function setSpecification ( Model\Specification $specification ) {

        $old                  = $this->_specification;
        $this->_specification = $specification;

        return $old;
    }

    /**
     * Get specification.
     *
     * @access  public
     * @return  \Hoa\Praspel\Model\Specification
     */
    public function getSpecification ( ) {

        return $this->_specification;
    }

    /**
     * Generate data from the @requires clause.
     *
     * @access  public
     * @return  array
     */
    public function generateData ( ) {

        $data          = array();
        $specification = $this->getSpecification();

        if(false === $specification->clauseExists('requires'))
            return $data;

        foreach($specification->getClause('requires') as $name => $variable)
            $data[$name] = $variable->sample();

        $this->setData($data);

        return $data;
    }

    /**
     * Set data.
     *
     * @access  public
     * @param   array  $data    Data.
     * @return  array
     */
    public function setData ( Array $data ) {

        $old         = $this->_data;
        $this->_data = $data;

        return $old;
    }

    /**
     * Get data.
     *
     * @access  public
     * @return  array
     */
    public function getData ( ) {

        return $this->_data;
    }

    /**
     * Set callable.
     *
     * @access  protected
     * @param   \Hoa\Core\Consistency\Xcallable  $callable    Callable.
     * @return  \Hoa\Core\Consistency\Xcallable
     */
    protected function setCallable ( \Hoa\Core\Consistency\Xcallable $callable ) {

        $old             = $this->_callable;
        $this->_callable = $callable;

        return $old;
    }

    /**
     * Get callable.
     *
     * @access  public
     * @return  \Hoa\Core\Consistency\Xcallable
     */
    public function getCallable ( ) {

        return $this->_callable;
    }

    /**
     * Get visitor Praspel.
     *
     * @access  protected
     * @return  \Hoa\Praspel\Visitor\Praspel
     */
    protected function getVisitorPraspel ( ) {

        if(null === $this->_visitorPraspel)
            $this->_visitorPraspel = new Visitor\Praspel();

        return $this->_visitorPraspel;
    }

    /**
     * Short interpreter.
     *
     * @access  public
     * @param   string  $praspel    Praspel.
     * @return  \Hoa\Praspel\Model\Clause
     */
    public static function interprete ( $praspel ) {

        static $_compiler    = null;
        static $_interpreter = null;

        if(null === $_compiler)
            $_compiler = \Hoa\Compiler\Llk::load(
                new \Hoa\File\Read('hoa://Library/Praspel/Grammar.pp')
            );

        if(null === $_interpreter)
            $_interpreter = new Visitor\Interpreter();

        $ast = $_compiler->parse($praspel);

        return $_interpreter->visit($ast);
    }

    /**
     * Extract Praspel (as a string) from a comment.
     *
     * @access  public
     * @param   string  $comment    Comment.
     * @return  string
     * @throw   \Hoa\Praspel\Exception
     */
    public static function extractFromComment ( $comment ) {

        $i = preg_match('#/\*(.*?)\*/#s', $comment, $matches);

        if(0 === $i)
            throw new Exception\Generic(
                'Not able to extract Praspel from the following ' .
                'comment:' . "\n" . '%s', 1, $comment);

        $i = preg_match_all('#^[\s\*]*\s*\*\s?([^\n]*)$#m', $matches[1], $maatches);

        if(0 === $i)
            throw new Exception\Generic(
                'Not able to extract Praspel from the following ' .
                'comment:' . "\n" . '%s', 2, $comment);

        return trim(implode("\n", $maatches[1]));
    }
}

}

namespace {

/**
 * Alias of \Hoa\Praspel::interprete().
 *
 * @access  public
 * @param   string  $praspel    Praspel
 * @return  \Hoa\Praspel\Model\Clause
 */
if(!ƒ('praspel')) {
function praspel ( $praspel ) {

    return \Hoa\Praspel::interprete($praspel);
}}
}
