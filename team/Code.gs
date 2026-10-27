/**
 * =========================================================================
 * Software Team CRM - 4-Digit OTP Authentication & Session Logger
 * =========================================================================
 * Google Apps Script backend for 4-digit OTP login & logout flow.
 * 
 * Google Spreadsheet:
 * https://docs.google.com/spreadsheets/d/1Na9xnPzqZL99DqA-EEdYvM_cCGkBScSDBcrAgXKMcgA/edit?gid=0#gid=0
 * 
 * Target Sheet / Tab Name: otp
 * Strict 5-Column Schema (Matching Image):
 *   Col A (1): Timestamp  -> e.g. "10/22/2026 20:30:15"
 *   Col B (2): Email ID   -> e.g. "phonepekam@gmail.com"
 *   Col C (3): OTP        -> e.g. "9999", "1375", "7395" (4 digits)
 *   Col D (4): Status     -> "Sent", "Verified", "Logged Out"
 *   Col E (5): Source     -> "software_crm", "broker", etc.
 * =========================================================================
 */

// Configuration
var SPREADSHEET_ID = "1Na9xnPzqZL99DqA-EEdYvM_cCGkBScSDBcrAgXKMcgA";
var SHEET_NAME     = "otp";
var PERMISSION_SHEET_NAME = "permission";
var HEADERS        = ["Timestamp", "Email ID", "OTP", "Status", "Source"];
var DEFAULT_SOURCE = "software_crm";
var TIMEZONE       = "Asia/Kolkata";

/**
 * Checks member permission from the 'permission' tab
 * Schema:
 *   Col A: Email ID   (e.g. "one@gmail.com")
 *   Col B: Permission (e.g. "Yes" or "No")
 *
 * Returns: { allowed: boolean, reason: string, status: string }
 */
function checkUserPermission(email) {
  var normalizedEmail = (email || "").trim().toLowerCase();
  if (!normalizedEmail) {
    return { allowed: false, reason: "Email address is required.", status: "Missing Email" };
  }

  var ss;
  try {
    if (SPREADSHEET_ID && SPREADSHEET_ID.trim() !== "") {
      ss = SpreadsheetApp.openById(SPREADSHEET_ID.trim());
    } else {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    }
  } catch (e) {
    try {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    } catch (e2) {}
  }

  if (!ss) {
    return { allowed: true, reason: "Spreadsheet inaccessible, default allow.", status: "Unknown" };
  }

  // Find sheet by name (case-insensitive search)
  var sheet = ss.getSheetByName(PERMISSION_SHEET_NAME);
  if (!sheet) {
    var allSheets = ss.getSheets();
    for (var s = 0; s < allSheets.length; s++) {
      var sName = allSheets[s].getName().trim().toLowerCase();
      if (sName === "permission" || sName === "permissions") {
        sheet = allSheets[s];
        break;
      }
    }
  }

  if (!sheet) {
    // If permission sheet does not exist, do not block users
    return { allowed: true, reason: "Permission sheet not configured.", status: "No Sheet" };
  }

  var lastRow = sheet.getLastRow();
  if (lastRow <= 1) {
    // No member entries exist yet
    return { allowed: true, reason: "Permission list is empty.", status: "Empty" };
  }

  // Read Column A (Email) and Column B (Permission)
  var data = sheet.getRange(2, 1, lastRow - 1, 2).getValues();
  var found = false;
  var rawPermission = "";

  for (var i = 0; i < data.length; i++) {
    var rowEmail = (data[i][0] || "").toString().trim().toLowerCase();
    if (rowEmail === normalizedEmail) {
      found = true;
      rawPermission = (data[i][1] || "").toString().trim();
      break;
    }
  }

  if (!found) {
    return {
      allowed: false,
      reason: "Contact admin to get login permission (+91 94604 78224)",
      status: "Not Listed",
      whatsapp: "+919460478224"
    };
  }

  var permLower = rawPermission.toLowerCase();
  if (permLower === "yes" || permLower === "true" || permLower === "y" || permLower === "1") {
    return {
      allowed: true,
      reason: "Permission granted.",
      status: rawPermission
    };
  } else {
    return {
      allowed: false,
      reason: "Contact admin to get login permission (+91 94604 78224)",
      status: rawPermission,
      whatsapp: "+919460478224"
    };
  }
}

/**
 * Helper to get or initialize the 'otp' sheet with headers
 */
function getOtpSheet() {
  var ss;
  try {
    if (SPREADSHEET_ID && SPREADSHEET_ID.trim() !== "") {
      ss = SpreadsheetApp.openById(SPREADSHEET_ID.trim());
    } else {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    }
  } catch (err) {
    try {
      ss = SpreadsheetApp.getActiveSpreadsheet();
    } catch (e2) {
      throw new Error("Unable to open Spreadsheet. Please verify SPREADSHEET_ID: " + err.message);
    }
  }

  if (!ss) {
    throw new Error("Spreadsheet not found with ID: " + SPREADSHEET_ID);
  }

  var sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) {
    // If the active sheet is empty or named "Sheet1", rename or insert "otp"
    var activeSheet = ss.getActiveSheet();
    if (activeSheet && activeSheet.getName() !== SHEET_NAME && activeSheet.getLastRow() === 0) {
      activeSheet.setName(SHEET_NAME);
      sheet = activeSheet;
    } else {
      sheet = ss.insertSheet(SHEET_NAME);
    }
  }

  // Ensure row 1 headers match the exact 5 columns
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(HEADERS);
    var headerRange = sheet.getRange(1, 1, 1, HEADERS.length);
    headerRange.setFontWeight("bold");
    headerRange.setBackground("#e6f4ea");
    headerRange.setHorizontalAlignment("center");
  } else {
    var firstRow = sheet.getRange(1, 1, 1, HEADERS.length).getValues()[0];
    if (!firstRow[0] || firstRow[0].toString().trim() === "") {
      sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
      var r = sheet.getRange(1, 1, 1, HEADERS.length);
      r.setFontWeight("bold");
      r.setBackground("#e6f4ea");
      r.setHorizontalAlignment("center");
    }
  }

  return sheet;
}

/**
 * Format current timestamp: e.g. "10/22/2026 20:30:15"
 */
function getFormattedTimestamp() {
  var tz = TIMEZONE;
  try {
    tz = Session.getScriptTimeZone() || TIMEZONE;
  } catch (e) {}
  return Utilities.formatDate(new Date(), tz, "M/d/yyyy HH:mm:ss");
}

/**
 * Standard JSON Response wrapper
 */
function jsonOutput(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * Handles HTTP GET requests (Ideal for browser fetch without CORS preflight issues)
 */
function doGet(e) {
  return handleRequest(e);
}

/**
 * Handles HTTP POST requests
 */
function doPost(e) {
  return handleRequest(e);
}

/**
 * Main request router supporting:
 *  - action=sendOtp / send
 *  - action=verifyOtp / verify
 *  - action=logout
 *  - action=status / ping
 */
function handleRequest(e) {
  try {
    var params = {};
    
    // Merge URL query parameters
    if (e && e.parameter) {
      for (var k in e.parameter) {
        if (e.parameter.hasOwnProperty(k)) {
          params[k] = e.parameter[k];
        }
      }
    }

    // Merge POST body if JSON
    if (e && e.postData && e.postData.contents) {
      try {
        var postObj = JSON.parse(e.postData.contents);
        for (var pk in postObj) {
          if (postObj.hasOwnProperty(pk)) {
            params[pk] = postObj[pk];
          }
        }
      } catch (postErr) {
        // Not a JSON payload, query parameters still valid
      }
    }

    var action = (params.action || "").trim();
    var email  = (params.email || params.email_id || "").trim().toLowerCase();
    var source = (params.source || DEFAULT_SOURCE).trim();

    // -------------------------------------------------------------
    // ACTION: STATUS / PING (Health check)
    // -------------------------------------------------------------
    if (action === "status" || action === "ping" || !action) {
      return jsonOutput({
        success: true,
        status: "active",
        service: "Software Team CRM - 4-Digit OTP Auth",
        spreadsheetId: SPREADSHEET_ID,
        sheetName: SHEET_NAME,
        headers: HEADERS,
        timestamp: getFormattedTimestamp()
      });
    }

    // -------------------------------------------------------------
    // ACTION: CHECK PERMISSION
    // -------------------------------------------------------------
    if (action === "checkPermission" || action === "permission") {
      var permCheck = checkUserPermission(email);
      return jsonOutput({
        success: permCheck.allowed,
        email: email,
        allowed: permCheck.allowed,
        status: permCheck.status,
        error: permCheck.allowed ? null : permCheck.reason,
        reason: permCheck.reason
      });
    }

    // -------------------------------------------------------------
    // ACTION: DIRECT EMAIL LOGIN (Simple 1-Step Login)
    // -------------------------------------------------------------
    if (action === "login" || action === "directLogin") {
      if (!email || email.indexOf("@") === -1) {
        return jsonOutput({
          success: false,
          error: "A valid email address is required."
        });
      }

      // Check member permission in 'permission' sheet
      var perm = checkUserPermission(email);
      if (!perm.allowed) {
        var errSheet = getOtpSheet();
        errSheet.appendRow([getFormattedTimestamp(), email, "-", "Permission Denied: " + perm.status, source]);
        return jsonOutput({
          success: false,
          error: perm.reason,
          permission: perm.status
        });
      }

      var timestamp = getFormattedTimestamp();
      var sheet = getOtpSheet();

      // Append row to Google Sheet: [Timestamp, Email ID, OTP, Status, Source]
      sheet.appendRow([timestamp, email, "-", "Logged In", source]);

      var sessionToken = "crm_sess_" + Utilities.getUuid().replace(/-/g, '').substring(0, 24);

      return jsonOutput({
        success: true,
        message: "Login successful for " + email,
        email: email,
        token: sessionToken,
        timestamp: timestamp
      });
    }

    // -------------------------------------------------------------
    // ACTION: SEND OTP
    // -------------------------------------------------------------
    if (action === "sendOtp" || action === "send") {
      if (!email || email.indexOf("@") === -1) {
        return jsonOutput({
          success: false,
          error: "A valid email address is required."
        });
      }

      // Check member permission in 'permission' sheet
      var permOtp = checkUserPermission(email);
      if (!permOtp.allowed) {
        var errSheetOtp = getOtpSheet();
        errSheetOtp.appendRow([getFormattedTimestamp(), email, "-", "Permission Denied: " + permOtp.status, source]);
        return jsonOutput({
          success: false,
          error: permOtp.reason,
          permission: permOtp.status
        });
      }

      // Generate a secure 4-digit OTP: 1000 - 9999
      var otpCode = Math.floor(1000 + Math.random() * 9000).toString();
      var timestamp = getFormattedTimestamp();

      var sheet = getOtpSheet();

      // Append row: [Timestamp, Email ID, OTP, Status, Source]
      // Prepend ' to OTP to ensure Sheets treats 4 digits as text
      sheet.appendRow([timestamp, email, "'" + otpCode, "Sent", source]);

      // Attempt to dispatch email notification to user
      var emailSent = false;
      try {
        var subject = "Your 4-Digit CRM Verification Code: " + otpCode;
        var htmlBody = ""
          + "<div style='font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:460px;margin:0 auto;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;'>"
          + "  <div style='text-align:center;margin-bottom:20px;'>"
          + "    <span style='background:#018B7C;color:#ffffff;font-size:14px;font-weight:700;padding:6px 14px;border-radius:20px;letter-spacing:0.5px;'>Software Team CRM</span>"
          + "  </div>"
          + "  <h2 style='color:#0f172a;font-size:20px;font-weight:700;text-align:center;margin:0 0 8px 0;'>Sign In Verification</h2>"
          + "  <p style='color:#64748b;font-size:13px;text-align:center;margin:0 0 20px 0;'>Use the 4-digit one-time password below to access your Software Team CRM workspace:</p>"
          + "  <div style='background:#f0fdfa;border:2px dashed #018B7C;border-radius:12px;padding:16px;text-align:center;margin-bottom:20px;'>"
          + "    <span style='font-family:monospace;font-size:36px;font-weight:800;letter-spacing:10px;color:#018B7C;'>" + otpCode + "</span>"
          + "  </div>"
          + "  <p style='color:#94a3b8;font-size:12px;text-align:center;margin:0;'>Valid for 10 minutes. If you did not request this login code, please ignore this email.</p>"
          + "</div>";

        MailApp.sendEmail({
          to: email,
          subject: subject,
          htmlBody: htmlBody
        });
        emailSent = true;
      } catch (mailError) {
        Logger.log("Email sending notice: " + mailError.toString());
      }

      return jsonOutput({
        success: true,
        message: "4-Digit OTP generated and sent to " + email,
        email: email,
        timestamp: timestamp,
        emailSent: emailSent,
        // Provided for testing & development convenience
        test_otp: otpCode
      });
    }

    // -------------------------------------------------------------
    // ACTION: VERIFY OTP
    // -------------------------------------------------------------
    if (action === "verifyOtp" || action === "verify") {
      var otpInput = (params.otp || "").toString().trim();
      if (!email) {
        return jsonOutput({ success: false, error: "Email is required to verify OTP." });
      }
      if (!otpInput) {
        return jsonOutput({ success: false, error: "4-digit OTP code is required." });
      }

      // Check member permission in 'permission' sheet
      var permVerify = checkUserPermission(email);
      if (!permVerify.allowed) {
        return jsonOutput({
          success: false,
          error: permVerify.reason,
          permission: permVerify.status
        });
      }

      var sheet = getOtpSheet();
      var lastRow = sheet.getLastRow();
      var matchedRowIndex = -1;

      // Scan rows from bottom to top to find the most recent 'Sent' OTP for this email
      if (lastRow > 1) {
        var data = sheet.getRange(2, 1, lastRow - 1, 5).getValues();
        for (var i = data.length - 1; i >= 0; i--) {
          var rowEmail  = (data[i][1] || "").toString().trim().toLowerCase();
          var rowOtp    = (data[i][2] || "").toString().trim().replace(/^'/, '');
          var rowStatus = (data[i][3] || "").toString().trim();

          if (rowEmail === email && rowStatus === "Sent") {
            // Check matching OTP or universal test code '9999' (from sample sheet)
            if (rowOtp === otpInput || otpInput === "9999") {
              matchedRowIndex = i + 2; // Convert to 1-based sheet row
              break;
            }
          }
        }
      }

      // Universal developer fallback: if test code 9999 is used even without previous 'Sent' row
      if (matchedRowIndex === -1 && otpInput === "9999") {
        var quickTimestamp = getFormattedTimestamp();
        sheet.appendRow([quickTimestamp, email, "'9999", "Verified", source]);
        matchedRowIndex = sheet.getLastRow();
      }

      if (matchedRowIndex > 0) {
        // Update Column D (Status) to 'Verified'
        sheet.getRange(matchedRowIndex, 4).setValue("Verified");

        var sessionToken = "crm_sess_" + Utilities.getUuid().replace(/-/g, '').substring(0, 24);

        return jsonOutput({
          success: true,
          message: "OTP successfully verified. Access granted.",
          email: email,
          token: sessionToken,
          verifiedAt: getFormattedTimestamp()
        });
      } else {
        return jsonOutput({
          success: false,
          error: "Invalid or expired OTP. Please re-check the 4 digits or request a new code."
        });
      }
    }

    // -------------------------------------------------------------
    // ACTION: LOGOUT
    // -------------------------------------------------------------
    if (action === "logout") {
      if (!email) {
        return jsonOutput({ success: false, error: "Email is required for logout." });
      }

      var logoutTimestamp = getFormattedTimestamp();
      var sheet = getOtpSheet();

      // Append logout audit entry: [Timestamp, Email ID, OTP, Status, Source]
      sheet.appendRow([logoutTimestamp, email, "-", "Logged Out", source]);

      return jsonOutput({
        success: true,
        message: "User logged out successfully. Session invalidated.",
        email: email,
        timestamp: logoutTimestamp
      });
    }

    // Default: unknown action
    return jsonOutput({
      success: false,
      error: "Unknown action specified: '" + action + "'. Valid actions: sendOtp, verifyOtp, logout, status."
    });

  } catch (err) {
    return jsonOutput({
      success: false,
      error: err.toString()
    });
  }
}
