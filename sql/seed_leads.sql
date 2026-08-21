-- Optional: your existing 11 leads from the July 15-20 sheet, pre-loaded.
-- Import this AFTER schema.sql if you want a populated starting point.

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-20', 'call_in', 'Grade 5', '779111286', 'a.m c nishantha', 'a.m.yonal sasen', 'vidiyaloka collage', 'habaraduwa', 'Nishantha Aththanayaka', 'Sinhala school, needs a transfer to the grade 6', 'need_to_discuss_first', 'other', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-20', 'He will update');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-20', 'call_in', 'grade_8', '713480702', 'dilhani gamage', 'I. b. sakithma', 'h /therapuththa m.v', 'ambalanthota', '711104601', '', 'need_to_discuss_first', 'other', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-20', 'No Answer');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-20', 'Call back later');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-20', 'Not Interested');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-19', 'call_in', 'grade_8', '763229822', 'MG Shamila sudarshani', 'K.mihin ranhinda', 'R/ Moraketiya central collage', 'Embilipitiya', 'M.G Shamila sudarshani', 'Currently in gov school, Earlier learned cambrdige school- send the location', 'next_term', 'better_academic_support', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-19', 'Fixed the appoint on Thursday - 30th before 1pm');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-19', 'call_in', 'other', '759134851', 'Hiruni hansika ranaweera', 'N.A.devidi vimansa/ N.A. denudi mahinsa', 'Narawelpita south central college Hakmana', 'Hakmana..narawelpita', 'peduru arachchige sripathi senarathna', 'currently in gov school - grade 5 and 2', 'within_1_month', 'better_english-medium_environment', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-19', 'Call him on 10th of August to fix an appointment');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-19', 'call_in', 'grade_9', '718870095', 'L.N.Manamperi', 'P.H Shehara', 'asian grammar s.', 'walgama', 'Lakmini Manamperi', 'Thinking whether to transfer the child', 'next_term', 'current_school_concerns', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-19', 'Call her on 12th August to fix an appointment after 14th');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-18', 'call_in', 'grade_10', '763108128', 'රන්ජිත් උඩගෙදර', 'ආදිත්‍ය', '........', 'මාතලේ', 'Damith Dk', '', 'need_to_discuss_first', 'other', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-18', 'Not Responded');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-18', 'Not Responded');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-18', 'Not Responded');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-18', 'call_in', 'LKG', '767474061', 'saNDUNI ishara', 'eduth asher', 'pep home', 'polhena', 'Saduni Ishara', 'child''s age is 4 and 8 months - recommeded UKG', 'need_to_discuss_first', 'better_academic_support', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-18', 'Interested -will Discuss and let us know');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-18', 'No answer');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-18', 'Booked an appointment - 28th June 10 am');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-18', 'call_in', 'grade_8', '772005555', 'Chamil Ratnayake', 'Yenuli Ratnayake', 'Sussex College', 'Imaduwa, Galle', 'Chiranthi Art', 'He needs to transfer to a better schol', 'need_to_discuss_first', 'better_english-medium_environmen', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-18', 'He will inform before coming to the school');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-22', 'call_in', 'other', '710429837', 'Ravi Jayawardana', 'Jayawardana', 'ss', 'matara', 'Ravi Jayawardana', '', 'next_term_(ඊළඟ_වාරයේ)', 'better_academic_support', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-22', 'Line Busy');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-22', 'Line Busy');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-22', 'call after 3');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-21', 'call_in', 'other', '706330095', 'khsd sanjeewa', 'khs nethsara', 'vijitha jathika pasala', 'dickwella', 'Suneth Dayan Sanjeewa', '', 'need_to_discuss_first_(පළමුව_විස්තර_සාකච්ඡා_කළ_යුතුයි)', 'better_academic_support', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-21', 'No answer');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-21', 'She will call again');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-21', 'No answer');

INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name, current_school, location, fb_name, inquiry_notes, transfer_period, reason, status) VALUES ('2026-07-21', 'call_in', 'grade_8', '763121640', 'sumithi', 'tisha', 'Siddharth vidyalaya', 'galla', 'Manjula Ramsi', '', 'immediately_(වහාම)', 'better_academic_support', 'new');
SET @last_lead := LAST_INSERT_ID();
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 1, '2026-07-21', 'Mobile not respondant');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 2, '2026-07-21', 'Mobile not respondant');
INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (@last_lead, 3, '2026-07-21', 'Mobile not respondant');

