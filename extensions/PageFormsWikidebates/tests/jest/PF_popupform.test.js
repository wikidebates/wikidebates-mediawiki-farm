'use strict';

const $ = require( '../../../../resources/lib/jquery/jquery.js' );

let wait;

beforeEach( () => {
	jest.useFakeTimers();
	global.jQuery = $;
	window.ext = {};
	require( '../../libs/PF_popupform.js' );
	wait = window.ext.popupform.waitForInnerSubmission;
} );

afterEach( () => {
	jest.clearAllTimers();
	jest.useRealTimers();
	jest.resetModules();
} );

test( 'popup waits for inner validation, then submits exactly once', () => {
	let validated = null;
	const submit = jest.fn();
	const done = jest.fn();
	wait( () => validated, submit, done );
	jest.advanceTimersByTime( 30 );
	expect( submit ).not.toHaveBeenCalled();
	validated = true;
	jest.advanceTimersByTime( 10 );
	expect( submit ).toHaveBeenCalledTimes( 1 );
	expect( done ).toHaveBeenCalledTimes( 1 );
	jest.advanceTimersByTime( 500 );
	expect( submit ).toHaveBeenCalledTimes( 1 );
} );

test( 'rejected validation never triggers a popup POST', () => {
	let validated = null;
	const submit = jest.fn();
	const done = jest.fn();
	wait( () => validated, submit, done );
	validated = false;
	jest.advanceTimersByTime( 10 );
	expect( submit ).not.toHaveBeenCalled();
	expect( done ).toHaveBeenCalledTimes( 1 );
} );

test( 'missing callback cannot create an infinite 10ms polling loop', () => {
	const readState = jest.fn( () => null );
	const submit = jest.fn();
	const done = jest.fn();
	wait( readState, submit, done );
	jest.advanceTimersByTime( 35010 );
	expect( submit ).not.toHaveBeenCalled();
	expect( done ).toHaveBeenCalledTimes( 1 );
	const checks = readState.mock.calls.length;
	jest.advanceTimersByTime( 30000 );
	expect( readState ).toHaveBeenCalledTimes( checks );
} );

test( 'closing or navigating a popup cancels a pending validation', () => {
	const submit = jest.fn();
	const done = jest.fn();
	const cancel = wait( () => true, submit, done );
	cancel();
	cancel();
	jest.advanceTimersByTime( 35010 );
	expect( submit ).not.toHaveBeenCalled();
	expect( done ).toHaveBeenCalledTimes( 1 );
} );
