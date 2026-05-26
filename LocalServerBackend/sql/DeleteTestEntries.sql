-- Deletes all entries from rounds, participants, roundLogs, & habitSurvey that are linked to an email starting with "test..."
DELETE FROM roundLogs   WHERE participantEmail LIKE 'test%';
DELETE FROM habitSurvey WHERE participantEmail LIKE 'test%';
DELETE FROM rounds      WHERE participantEmail LIKE 'test%';
DELETE FROM participants WHERE email           LIKE 'test%';
