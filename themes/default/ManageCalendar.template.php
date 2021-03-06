<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Beta 2
 *
 */

/**
 * Editing or adding holidays.
 */
function template_edit_holiday()
{
	global $context, $scripturl, $txt, $modSettings;

	// Show a form for all the holiday information.
	echo '
	<div id="admincenter">
		<form action="', $scripturl, '?action=admin;area=managecalendar;sa=editholiday" method="post" accept-charset="UTF-8">
			<h2 class="category_header">', $context['page_title'], '</h2>
			<div class="windowbg">
				<div class="content">
					<dl class="settings">
						<dt class="small_caption">
							<strong><label for="title">', $txt['holidays_title_label'], '</label>:</strong>
						</dt>
						<dd class="small_caption">
							<input type="text" id="title" name="title" value="', $context['holiday']['title'], '" size="55" maxlength="60" />
						</dd>
						<dt class="small_caption">
							<strong><label for="year">', $txt['calendar_year'], '</label></strong>
						</dt>
						<dd class="small_caption">
							<select name="year" id="year" onchange="generateDays();">
								<option value="0000"', $context['holiday']['year'] == '0000' ? ' selected="selected"' : '', '>', $txt['every_year'], '</option>';

	// Show a list of all the years we allow...
	for ($year = $modSettings['cal_minyear']; $year <= $modSettings['cal_maxyear']; $year++)
		echo '
								<option value="', $year, '"', $year == $context['holiday']['year'] ? ' selected="selected"' : '', '>', $year, '</option>';

	echo '
							</select>
							<strong>&nbsp;<label for="month">', $txt['calendar_month'], '</label></strong>
							<select name="month" id="month" onchange="generateDays();">';

	// There are 12 months per year - ensure that they all get listed.
	for ($month = 1; $month <= 12; $month++)
		echo '
								<option value="', $month, '"', $month == $context['holiday']['month'] ? ' selected="selected"' : '', '>', $txt['months'][$month], '</option>';

	echo '
							</select>
							<strong>&nbsp;<label for="day">', $txt['calendar_day'], '</label></strong>
							<select name="day" id="day" onchange="generateDays();">';

	// This prints out all the days in the current month - this changes dynamically as we switch months.
	for ($day = 1; $day <= $context['holiday']['last_day']; $day++)
		echo '
								<option value="', $day, '"', $day == $context['holiday']['day'] ? ' selected="selected"' : '', '>', $day, '</option>';

	echo '
							</select>
						</dd>
					</dl>
					<hr />
					<div class="submitbutton">';

	if ($context['is_new'])
		echo '
						<input type="submit" value="', $txt['holidays_button_add'], '" class="button_submit" />';
	else
		echo '
						<input type="submit" name="edit" value="', $txt['holidays_button_edit'], '" class="button_submit" />
						<input type="submit" name="delete" value="', $txt['holidays_button_remove'], '" class="button_submit" />
						<input type="hidden" name="holiday" value="', $context['holiday']['id'], '" />';

	echo '
						<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
					</div>
				</div>
			</div>
		</form>
	</div>';
}