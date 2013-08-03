ALTER TABLE [#__users] ADD [otpKey] [nvarchar](max) NOT NULL DEFAULT '';

ALTER TABLE [#__users] ADD [otep] [nvarchar](max) NOT NULL DEFAULT '';

INSERT INTO #__extensions (extension_id, name, type, element, folder, client_id, enabled, access, protected, manifest_cache, params, custom_data, system_data, checked_out, checked_out_time, ordering, state)
SELECT 448, 'plg_twofactorauth_totp', 'plugin', 'totp', 'twofactorauth', 0, 1, 1, 0, '', '{}', '', '', 0, '1900-01-01 00:00:00', 0, 0;