<?php

namespace Tarot\Exception;

use RuntimeException;

/**
 * A write would give a row a name its table already holds (a UNIQUE name
 * column). The Data layer throws it in place of the database's constraint
 * error, so the controller can answer 409 instead of a 500.
 */
class NameTakenException extends RuntimeException {}
