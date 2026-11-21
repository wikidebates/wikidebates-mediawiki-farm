#!/usr/bin/env python3
"""Chromium smoke checks using actual bundled JS, simulated MediaWiki services.

Run from any directory with Python Playwright and Chromium installed:
    python extensions/PageFormsWikidebates/tests/browser/editing-smoke.py
No wiki API or production server is contacted.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json
import os

root = Path(__file__).resolve().parents[4]
libs = root / 'extensions/PageFormsWikidebates/libs'

errors = []
with sync_playwright() as p:
	browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
	page = browser.new_page(viewport={'width': 1000, 'height': 700})
	page.on('pageerror', lambda error: errors.append(str(error)))
	page.set_content('<!doctype html><html><head></head><body></body></html>')
	page.add_script_tag(path=str(root / 'resources/lib/jquery/jquery.js'))
	page.evaluate("""() => {
		window.pageforms = window.pf = {};
		const hooks = {};
		const config = {
			wgAction: 'formedit', wgPageName: 'Test', wgPageFormsDependentFields: [],
			wgPageFormsAutocompleteValues: {}, wgPageFormsShowOnSelect: {},
			wgPageFormsHeightForMinimizingInstances: -1,
			'ext.pf.initFunctionData': {}, 'ext.pf.validationFunctionData': {}
		};
		window.mw = window.mediaWiki = {
			config: { get: (key) => config[key] },
			msg: (key, ...args) => [key, ...args].join(':'),
			message: (key) => ({text:()=>key, escaped:()=>key}),
			util: { wikiScript:()=>'/api.php', getParamValue:()=>null },
			loader: { using:()=>Promise.resolve() },
			user: { options: { get:()=>false } },
			hook: (key) => hooks[key] || (hooks[key] = {
				handlers: [], add(fn){this.handlers.push(fn);}, fire(...args){this.handlers.forEach(fn=>fn(...args));}
			})
		};
		window.require = () => ({create:()=>({destroy(){}})});
	} """)
	page.add_style_tag(path=str(libs / 'foreign/select2/select2.css'))
	page.add_style_tag(content='.pf-hidden,.multipleTemplateStarter{display:none;} .inputSpan{display:block;margin:8px;}')
	for name in ['foreign/select2/select2', 'ext.pf.select2.base', 'ext.pf.select2.tokens', 'PF_ComboBoxInput', 'PF_lazyWidgets', 'PF_dependentFields', 'PF_formErrors', 'PF_instances', 'PF_editState', 'PF_drafts', 'PF_formSubmission', 'PageForms']:
		page.add_script_tag(path=str(libs / (name + '.js')))
	page.wait_for_timeout(30)
	page.evaluate("""() => {
		document.body.innerHTML = `<form id="pfForm">
		<label for="near">Near</label><select id="near" class="pfComboBox" name="Form[Near]"><option value="a">Alpha</option></select>
		<div class="multipleTemplateWrapper"><div class="multipleTemplateList">
		<div class="multipleTemplateInstance"><div class="multipleTemplateInstanceTable">
		<div class="instanceRearranger"></div><div class="instanceMain"><span class="inputSpan mandatoryFieldSpan"><label for="body">Citation</label><textarea id="body" name="Quote[0a][Text]" class="mandatoryField"></textarea></span></div><a class="removeButton"></a></div></div>
		</div></div>
		<span class="inputSpan"><select id="tokens" class="pfTokens" name="Form[Tokens][]" multiple autocompletesettings="Local,list,;"><option value="one" selected>One</option></select></span>
		<div style="height:2500px"></div>
		<select id="far" class="pfComboBox" name="Form[Far]"><option value="b">Beta</option></select>
		<div class="editButtons"><button type="submit" name="wpSave" value="Save">Save</button></div></form>`;
		const form = $('#pfForm');
		form.initializeJSElements(false);
		form.find('.multipleTemplateInstance').initializeJSElements(true);
		mw.pageFormsInstances.update(form);
		mw.pageFormsEditState.bind(form);
		mw.pageFormsErrors.bind(form);
		mw.pageFormsSubmission.bind(form);
		window.sent = 0;
		document.querySelector('form').addEventListener('submit',event=>{event.preventDefault();window.sent++;});
	} """)
	page.wait_for_function("!!jQuery('#near').data('select2')")
	assert page.evaluate("!jQuery('#far').data('select2')"), 'Offscreen combobox initialized too early'
	assert page.evaluate("jQuery('#far').val() === 'b'"), 'Lazy field value lost'
	page.locator('#far').scroll_into_view_if_needed()
	page.wait_for_function("!!jQuery('#far').data('select2')")
	page.locator('button[name=wpSave]').click()
	page.wait_for_function("document.activeElement.id === 'body'")
	assert page.evaluate('window.sent === 0'), 'Invalid form submitted'
	page.locator('#body').fill('Citation body')
	page.wait_for_function("document.querySelectorAll('.errorMessage').length === 0")
	page.evaluate("mw.pageFormsInstances.remove(jQuery('.multipleTemplateInstance'))")
	page.locator('.pf-instance-undo button').click()
	assert page.locator('#body').input_value() == 'Citation body'
	page.evaluate("jQuery('#tokens').data('select2').selection.$search[0].focus()")
	page.evaluate("""() => {
		const widget = $('#tokens').data('select2');
		const input = widget.selection.$search[0];
		input.dispatchEvent(new CompositionEvent('compositionstart',{bubbles:true}));
		input.value = '\u00ea';
		input.dispatchEvent(new InputEvent('input',{bubbles:true,isComposing:true}));
		input.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true,data:'\u00ea'}));
	} """)
	assert page.evaluate("jQuery('#tokens').data('select2').selection.$search.val() === '\u00ea'")
	assert page.evaluate("document.activeElement === jQuery('#tokens').data('select2').selection.$search[0]")
	page.evaluate("jQuery('#tokens').select2('close')")
	page.locator('button[name=wpSave]').click()
	page.wait_for_timeout(300)
	page.wait_for_function('window.sent === 1', timeout=3000)
	assert not errors, '\n'.join(errors)
	print(json.dumps({'browser':browser.version, 'checks': ['visible-only initialization', 'offscreen value retained', 'scroll initialization', 'invalid submit prevented', 'error focus', 'progressive repair', 'undo restores values', 'composition and focus', 'single valid submission'], 'page_errors':errors}, indent='\t'))
	browser.close()
