<?php
namespace mimus {

	class Double {

		public static function class(string $class, bool $reset = true) {
			if (!class_exists($class)) {
				throw new \LogicException(
					"{$class} does not exist or is not a class");
			}

			return self::definition($class, $reset);
		}

		public static function make(string $name, array $args, bool $reset = true) {
			return self::definition($name, $reset, ...$args);
		}

		public static function unlink(string $name) : void {
			if (!self::exists($name)) {
				throw new \LogicException(
					"{$name} has not been doubled");
			}

			unset(self::$doubles[$name]);
		}

		public static function exists(string $name) : bool {
			return isset(self::$doubles[$name]);
		}

		public static function clear() : void {
			self::$doubles = [];
		}

		private static function definition($name, $reset, ...$args) {
			if (!isset(Double::$doubles[$name])) {
				double::$doubles[$name] = 
					new self(new \Componere\Definition($name, ...$args));
			} else if ($reset) {
				double::$doubles[$name]->reset();
			}

			return double::$doubles[$name];
		}

		private function __construct(\Componere\Definition $definition) {
			$this->definition = $definition;
			$this->reflector  = $this->definition->getReflector();

			foreach ($this->reflector->getInterfaceNames() as $interface) {
				$this->interfaces[$interface] = true;
			}

			if ($this->reflector->isAbstract()) {
				throw new \LogicException(
					"cannot mock an abstract class");
			}
		}

		private function build() {
			$closures = $this->definition->getClosures();

			foreach ($this->reflector->getMethods() as $prototype) {
				$static    = $prototype->isStatic();
				$name      = $prototype->getName();

				if (!isset($closures[$name])) {
					continue;
				}

				$closure   = $closures[$name];

				$this->table[$name] = [];

				$table     =& $this->table[$name];

				$implementation = new \Componere\Method(function(...$args) 
							use($static, $closure, &$table) {
					$except = null;
					$path    = null;

					if (!$table) {
						return;
					}

					foreach ($table as $idx => $rule) {
						try {
							$path = $rule->match($except, true, ...$args);
						} catch (Exception $ex) {
							$except = $ex;
						}

						if ($path) {
							$except = null;
							break;
						}
					}

					if ($except) {
						throw $except;
					}

					if ($static) {
						return $path->travel(null, $closure, ...$args);
					}

					return $path->travel($this, $closure, ...$args);
				});

				if ($static) {
					$implementation->setStatic();
				}

				if ($prototype->isPrivate()) {
					$implementation->setPrivate();
				} else if ($prototype->isProtected()) {
					$implementation->setProtected();
				}

				if ($prototype->isFinal()) {
					$implementation->setFinal();
				}

				$this->definition->addMethod($name, $implementation);
			}
			
			$this->definition->register();		
		}

		public function use(string $class, bool $partialize = false) : Double {
			if (isset($this->traits[$class])) {
				if ($partialize) {
					$this->partialize($class);
				}
				return $this;
			}

			if ($this->definition->isRegistered()) {
				throw new \LogicException(
					"use() must be invoked before rule() or getInstance()");
			}

			if (!trait_exists($class)) {
				throw new \LogicException(
					"trait {$class} does not exist, or is not a trait");
			}

			$this->definition->addTrait($class);

			if ($partialize) {
				$this->partialize($class);
			}

			$this->traits[$class] = true;

			return $this;
		}

		public function implements(string $class, bool $partialize = false) : Double {
			if (isset($this->interfaces[$class])) {
				if ($partialize) {
					$this->partialize($class);
				}
				return $this;
			}

			if ($this->definition->isRegistered()) {
				throw new \LogicException(
					"implements() must be invoked before rule() or getInstance()");
			}

			if (!interface_exists($class)) {
				throw new \LogicException(
					"interface {$class} does not exist, or is not an interface");
			}

			$this->definition->addInterface($class);

			if ($partialize) {
				$this->partialize($class);
			}

			$this->interfaces[$class] = true;

			return $this;
		}

		public function partialize($on, array $except = []) : Double {
			if (!is_array($on) && !is_string($on)) {
				throw new \LogicException(
					"expected an array of method names or the name of a valid class");
			}

			if (is_array($on) && count($except)) {
				throw new \LogicException(
					"expected a class name and array of excluded methods");
			}

			if (is_array($on)) {
				foreach ($on as $method) {
					if ($this->reset($method)) {
						$this->rule($method) /* must be first rule */
							->expects()
							->executes();
					}
				}
			} else {
				try {
					$reflector = new \ReflectionClass($on);
				} catch (\ReflectionException $re) {
					throw new \LogicException(
						"expected a valid class name, {$on} cannot be loaded");
				}

				foreach ($reflector->getMethods() as $method) {
					if (!count($except) || !in_array($method->getName(), $except)) {
						if ($this->reset($method->getName())) {
							$this->rule($method->getName())
								->expects()
								->executes();
						}
					}
				}
			}
			return $this;
		}

		public function defines(string $name, $value) : Double {
			if ($this->reflector->hasConstant($name)) {
				$this->definition->setConstant(
					$name, new \Componere\Value($value));
			} else {
				$this->definition->addConstant(
					$name, new \Componere\Value($value));
			}
			return $this;
		}

		public function rule(string $name) : Rule {
			self::commit();

			if (!isset($this->table[$name])) {
				throw new \LogicException(
					"method {$name} does not exist, or is whitelisted");
			}

			return $this->table[$name][] = new Rule($name);
		}

		public function reset(string $name = null) : bool {
			if (!$this->definition->isRegistered()) {
				return true;
			}

			if ($name === null) {
				foreach ($this->table as $name => $rules) {
					if (!$this->reset($name)) {
						return false;
					}
				}
				return true;
			} else if (isset($this->table[$name])) {
				foreach ($this->table[$name] as $idx => $rule) {
					unset($this->table[$name][$idx]);
				}
				return true;
			}

			return false;
		}

		public function commit() : void {
			if (!$this->definition->isRegistered()) {
				$this->build();
			}
		}

		public function getInstance(...$args) : object {
			self::commit();

			if (!func_num_args()) {
				return $this->reflector->newInstanceWithoutConstructor();
			}
			return $this->reflector->newInstanceArgs($args);
		}

		private $definition;
		private $traits     = [];
		private $interfaces = [];
		private $reflector;
		private $table;

		private static $doubles = [];
	}

	function printable($value) {
		switch (gettype($value)) {
			case 'null':
				return 'null';
			case 'boolean':
				return $value ? "bool(true)" : "bool(false)";
			case 'integer':
				return sprintf("int(%d)", $value);
			case 'double':
				return sprintf("float(%s)", $value);
			case 'string': /* TODO limit length */
				if (class_exists($value, 0))
					return $value;
				return sprintf("string(%d) \"%s\"", strlen($value), $value);
			case 'array': /* TODO limit length */
				return sprintf("array(%d) [%s]", count($value), implode(',', $value));
			case 'object':
				return get_class($value);
			default:
				return 'unknown';
		}
	}
}
