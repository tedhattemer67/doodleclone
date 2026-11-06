<?php
/**
 * dcs_format_range() timezone label (2.3.5): the abbreviation must match the
 * slot's own date, not today's.
 * Run from the repo root:  php tests/test-format-range.php
 */
require __DIR__ . '/bootstrap.php';

$at = fn( string $local ) => ( new DateTimeImmutable( $local, dcs_tz() ) )->getTimestamp();

section( 'A. Label follows the slot date' );
// Default DCS_TIMEZONE is America/New_York. Summer slot is EDT, winter slot
// is EST, whatever today's date is.
$summer = $at( '2027-07-15 14:00' );
$winter = $at( '2027-01-15 14:00' );
ok( dcs_format_range( $summer, $summer + 3600 ) === 'Jul 15, 2027 · 2:00pm – 3:00pm EDT', 'summer slot labelled EDT' );
ok( dcs_format_range( $winter, $winter + 3600 ) === 'Jan 15, 2027 · 2:00pm – 3:00pm EST', 'winter slot labelled EST' );

section( 'B. Either side of a clock change' );
$before = $at( '2027-03-13 10:00' ); // Saturday before spring forward (Mar 14, 2027)
$after  = $at( '2027-03-15 10:00' );
ok( str_ends_with( dcs_format_range( $before ), ' EST' ), 'day before spring change is EST' );
ok( str_ends_with( dcs_format_range( $after ), ' EDT' ), 'day after spring change is EDT' );
ok( str_ends_with( dcs_slot_range( [ 'start' => $after, 'duration_minutes' => 30 ] ), ' EDT' ), 'dcs_slot_range uses the slot date too' );

section( 'C. Edge cases unchanged' );
ok( dcs_format_range( 0 ) === '—', 'no start gives a dash' );

finish();
