INSERT INTO #__assets (id,parent_id,lft,rgt,level,name,title,rules)
SELECT 36,1,69,70,1,'com_otp','com_otp','{"core.admin":[],"core.manage":[],"core.delete":[],"core.edit.state":[]}';

INSERT INTO #__extensions (extension_id, name, type, element, folder, client_id, enabled, access, protected, manifest_cache, params, custom_data, system_data, checked_out, checked_out_time, ordering, state)
SELECT 30, 'com_otp', 'component', 'com_otp', '', 1, 1, 1, 1, '{"name":"com_otp","type":"component","creationDate":"August 2013","author":"Joomla! Project","copyright":"(C) 2005 - 2013 Open Source Matters. All rights reserved.","authorEmail":"admin@joomla.org","authorUrl":"www.joomla.org","version":"3.1.0","description":"COM_OTP_XML_DESCRIPTION","group":""}', '{}', '', '', 0, '1900-01-01 00:00:00', 0, 0;

ALTER TABLE [#__users] ADD [otpKey] [nvarchar](max) NOT NULL DEFAULT '';

ALTER TABLE [#__users] ADD [otep] [nvarchar](max) NOT NULL DEFAULT '';