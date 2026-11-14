# Added / modified / removed files — integrated frontend vs the ORIGINAL frontend (after the suspension-notification patch)

Byte-for-byte comparison with the original ZIP; excludes vendor/, node_modules/, .git/, dist/.

## Modified original files (10)
- src/App.jsx
- src/layouts/AdminLayout.jsx
- src/layouts/FinancialLayout.jsx
- src/layouts/InvestorLayout.jsx
- src/layouts/MarvflowLayout.jsx
- src/pages/investor/InvestorAnnouncements.jsx
- src/pages/investor/InvestorReferrals.jsx
- src/pages/investor/InvestorWithdrawals.jsx
- src/pages/public/RegisterPage.jsx
- src/services/api.js

## Removed from the package (1 + generated files under storage/)
- .env
- storage/logs/*.log, compiled views; vendor/, node_modules/, dist/

## Added (48)
- docs/integration/CHANGED_FILES_BACKEND.md
- docs/integration/CHANGED_FILES_FRONTEND.md
- docs/integration/ENVIRONMENT_AND_EXTERNAL_SERVICES.md
- docs/integration/FIRST_LOCAL_TEST_RUN_ANALYSIS.md
- docs/integration/INTEGRATION_COMPLETION_REPORT.md
- docs/integration/MAKER_CHECKER_PROPOSAL.md
- docs/integration/MIGRATION_AND_TEST_GUIDE.md
- docs/integration/MYSQL_CONCURRENCY_INVESTIGATION.md
- docs/integration/ORIGINAL_VS_INTEGRATED_COMPARISON.md
- docs/integration/REGISTRATION_SECURITY_REMEDIATION_REPORT.md
- docs/integration/SECURITY_AND_PERMISSIONS_REVIEW.md
- docs/integration/SHARED_FILE_CONFLICT_REPORT.md
- docs/integration/STALE_RESULTS_INVESTIGATION.md
- docs/integration/UNRESOLVED_ISSUES_AND_DECISIONS.md
- docs/integration/WITHDRAWAL_CANCELLATION_REVERSAL_PROPOSAL.md
- docs/integration/WITHDRAWAL_FINANCIAL_SECURITY_REVIEW.md
- src/components/RegistrationTrackingNotice.jsx
- src/components/news/NewsParts.jsx
- src/components/news/news.css
- src/hooks/useStaffAccess.js
- src/layouts/RegistrationLayout.jsx
- src/pages/admin/AdminMailing.css
- src/pages/admin/AdminMailing.jsx
- src/pages/admin/AdminNewsMedia.css
- src/pages/admin/AdminNewsMedia.jsx
- src/pages/financial/FinancialReferrals.jsx
- src/pages/financial/FinancialWithdrawalFees.jsx
- src/pages/investor/InvestorNewsHub.jsx
- src/pages/investor/InvestorUpdates.jsx
- src/pages/public/MailingForms.jsx
- src/pages/public/NewsPage.jsx
- src/pages/registration/RegistrationActivity.jsx
- src/pages/registration/RegistrationApplicationDetail.jsx
- src/pages/registration/RegistrationApplications.jsx
- src/pages/registration/RegistrationDashboard.jsx
- src/pages/registration/RegistrationNotifications.jsx
- src/pages/registration/RegistrationPages.css
- src/pages/registration/RegistrationProfile.jsx
- src/pages/staff/StaffMailing.jsx
- src/pages/staff/StaffNews.jsx
- src/services/mailingApi.js
- src/services/newsApi.js
- src/utils/registrationResume.js
- src/utils/registrationResume.test.js
- src/utils/registrationStatus.js
- src/utils/registrationStatus.test.js
- src/utils/registrationTracking.js
- src/utils/registrationTracking.test.js
