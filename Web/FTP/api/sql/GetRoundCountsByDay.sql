SELECT participantEmail, day, COUNT(*) as round_count
FROM r
GROUP BY participantEmail, day WITH ROLLUP;