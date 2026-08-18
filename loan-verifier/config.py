"""
config.py — All hard-coded settings for the loan verification script.
Edit this file if table names, column names, paths, or business rules change.
"""

import os

# =============================================================================
# FILE PATHS
# =============================================================================

DB_FOLDER = os.path.dirname(os.path.abspath(__file__))
SQL_FILENAME = "diamond_credit_test.sql"
SQL_FILE_PATH = os.environ.get("SQL_FILE_PATH") or os.path.join(DB_FOLDER, SQL_FILENAME)
REPORT_FOLDER = os.path.dirname(SQL_FILE_PATH)  # Reports saved next to the SQL file
REPORT_FILENAME_PREFIX = "verification_report"  # YYYY-MM-DD_HHMMSS appended at runtime — interest verifier (verify_loans.py)
PAYMENTS_REPORT_FILENAME_PREFIX = "payments_verification_report"  # payments/deals verifier (verify_payments.py)

# =============================================================================
# TABLE NAMES
# =============================================================================

TABLE_CONTRACTS = "contracts"
TABLE_TRANSACTIONS = "transactions"
TABLE_CONTRACT_AMOUNT_HISTORIES = "contract_amount_histories"
TABLE_PAYMENTS = "payments"
TABLE_CLIENTS = "clients"

TABLE_DEALS = "deals"

REQUIRED_TABLES = [
    TABLE_CONTRACTS,
    TABLE_TRANSACTIONS,
    TABLE_CONTRACT_AMOUNT_HISTORIES,
    TABLE_PAYMENTS,
    TABLE_CLIENTS,
    TABLE_DEALS,
]

# =============================================================================
# COLUMN NAMES — contracts table
# =============================================================================

COL_CONTRACT_ID = "id"
COL_CONTRACT_NUM = "num"
COL_CONTRACT_CLIENT_ID = "client_id"
COL_CONTRACT_ESTIMATED_AMOUNT = "estimated_amount"   # face/contracted amount (basis for interest)
COL_CONTRACT_PROVIDED_AMOUNT = "provided_amount"     # actual cash disbursed
COL_CONTRACT_INTEREST_RATE = "interest_rate"         # nominal daily rate in % (0.11 = 0.11%/day)
COL_CONTRACT_EFFECTIVE_DAILY_RATE = "effective_daily_rate"  # effective daily rate in %
COL_CONTRACT_STATUS = "status"
COL_CONTRACT_PROVIDED_AT = "provided_at"             # disbursement date
COL_CONTRACT_DEADLINE = "deadline"
COL_CONTRACT_CLOSED_AT = "closed_at"
COL_CONTRACT_PAYMENT_TYPE = "payment_type"           # 'classic' or 'amortized'
COL_CONTRACT_PAYMENT_SCHEDULE = "payment_schedule"   # JSON array for amortized loans
COL_CONTRACT_DATE = "date"                           # contract creation date (schedule anchor, ~= provided_at)
COL_CONTRACT_DEADLINE_DAYS = "deadline_days"         # number of monthly installments
COL_CONTRACT_PAYMENT_DAY = "payment_day"             # fixed due-day-of-month (NULL = due day tracks disbursement day)
COL_CONTRACT_PENALTY = "penalty"                     # daily penalty rate in % (0.13 = 0.13%/day)
COL_CONTRACT_LEFT = "left"                           # system's current remaining-principal debt figure
COL_CONTRACT_COLLECTED = "collected"                 # system's cumulative interest collected figure
COL_CONTRACT_PENALTY_AMOUNT = "penalty_amount"        # system's current outstanding penalty figure

# =============================================================================
# COLUMN NAMES — documents_journal table (real payment ledger, schedule-validator only)
# =============================================================================

TABLE_DOCUMENTS_JOURNAL = "documents_journal"

COL_DJ_ID = "id"
COL_DJ_DATE = "date"
COL_DJ_DOCUMENT_TYPE = "document_type"
COL_DJ_AMOUNT_AMD = "amount_amd"
COL_DJ_DEAL_ID = "deal_id"
COL_DJ_CONTRACT_ID = "contract_id"   # unreliable — only ~56% populated; join via deal_id instead
COL_DJ_DELETED_AT = "deleted_at"

# documents_journal.document_type strings for real principal/interest/penalty
# postings. Most postings use the Armenian display string, but PaymentControllerNew
# / DealUpdateService in the live backend also post repayments under separate
# English identifiers (DocumentJournal::PAY_MOTHER_AMOUNT_CASH etc.) for cash vs.
# transfer — confirmed on contract 56's early full payoff, which used
# pay_mother_amount_cash and was otherwise invisible to this matcher, making a
# fully-repaid loan look like it still owed its full original principal.
DOC_TYPE_PAY_MOTHER = {"Վարկերի մարում անվանական արժեքով", "pay_mother_amount_cash", "pay_mother_amount"}
DOC_TYPE_PAY_INTEREST = {"Տոկոսի մարում", "pay_interest_amount_cash", "pay_interest_amount"}
DOC_TYPE_PAY_PENALTY = {"Տուգանքի մարում", "pay_penalty_amount_cash", "pay_penalty_amount"}
DOC_TYPE_PENALTY_ACCRUAL = "Տուգանքի հաշվեգրում"            # daily penalty accrual (cron-posted)
DOC_TYPE_DISBURSEMENT = "Վարկի Տրամադրում-անվանական գումար"  # actual cash disbursed at origination —
                                                              # the schedule-validator's starting balance.
                                                              # NOT contracts.provided_amount, which is a
                                                              # live running balance the system decrements
                                                              # on every real principal repayment.

# =============================================================================
# COLUMN NAMES — transactions table
# =============================================================================

COL_TXN_ID = "id"
COL_TXN_DATE = "date"
COL_TXN_DOCUMENT_TYPE = "document_type"
COL_TXN_AMOUNT_AMD = "amount_amd"
COL_TXN_COMMENT = "comment"
COL_TXN_DELETED_AT = "deleted_at"

# Armenian document type strings for daily interest accruals
DOC_TYPE_NOMINAL = "Տոկոսային եկամուտ անվանական"    # Nominal interest income
DOC_TYPE_EFFECTIVE = "Տոկոսային եկամուտ արդյունավետ"  # Effective interest income

# Regex pattern to extract contract ID from transaction comment
# Matches: "...contract #42..." → group(1) = "42"
CONTRACT_ID_PATTERN = r"contract #(\d+)"

# =============================================================================
# COLUMN NAMES — contract_amount_histories table
# =============================================================================

COL_CAH_CONTRACT_ID = "contract_id"
COL_CAH_AMOUNT_TYPE = "amount_type"   # 'provided_amount' or 'estimated_amount'
COL_CAH_AMOUNT = "amount"
COL_CAH_TYPE = "type"                 # 'in' = disbursement, 'out' = repayment
COL_CAH_DATE = "date"
COL_CAH_DELETED_AT = "deleted_at"

CAH_AMOUNT_TYPE_PRINCIPAL = "provided_amount"  # The type we use for balance tracking

# =============================================================================
# COLUMN NAMES — payments table
# =============================================================================

COL_PAY_ID = "id"
COL_PAY_CONTRACT_ID = "contract_id"
COL_PAY_DATE = "date"
COL_PAY_AMOUNT = "amount"                        # Current total still due on this installment
COL_PAY_PRINCIPAL_PAYMENT = "principal_payment"  # Current outstanding principal (0 once fully paid)
COL_PAY_INTEREST_PAYMENT = "interest_payment"    # Current outstanding interest (0 once fully paid)
COL_PAY_ORIGINAL_PRINCIPAL_PAYMENT = "original_principal_payment"  # Actual principal portion paid to date
COL_PAY_ORIGINAL_INTEREST_PAYMENT = "original_interest_payment"    # Actual interest portion paid to date
COL_PAY_REMAINING = "remaining"      # Total outstanding contract balance after this installment
COL_PAY_TYPE = "type"               # 'regular', 'partial_payment', 'full_payment', etc.
COL_PAY_STATUS = "status"           # 'completed' or 'initial'
COL_PAY_PAID = "paid"               # Actual amount paid (NULL if not yet paid)
COL_PAY_DELETED_AT = "deleted_at"

PAYMENT_TYPE_REGULAR = "regular"    # Scheduled installment
PAYMENT_TYPE_PENALTY = "penalty"    # Penalty charge, dated on the actual (not scheduled) payment date
PAYMENT_STATUS_COMPLETED = "completed"

# =============================================================================
# COLUMN NAMES — deals table
# =============================================================================

COL_DEAL_ID = "id"
COL_DEAL_CONTRACT_ID = "contract_id"
COL_DEAL_DATE = "date"
COL_DEAL_TYPE = "type"               # 'in' = cash received, 'out' = cash disbursed
COL_DEAL_AMOUNT = "amount"           # Total cash amount
COL_DEAL_INTEREST_AMOUNT = "interest_amount"  # Interest portion (NULL for full/partial payoffs)
COL_DEAL_PENALTY = "penalty"         # Penalty portion — excluded from effective balance reduction
COL_DEAL_FILTER_TYPE = "filter_type" # 'payment', 'full_payment', 'partial_payment', 'contract', ...
COL_DEAL_DELETED_AT = "deleted_at"

DEAL_PAYMENT_FILTER_TYPES = {"payment", "full_payment", "partial_payment"}

# =============================================================================
# COLUMN NAMES — clients table
# =============================================================================

COL_CLIENT_ID = "id"
COL_CLIENT_SURNAME = "surname"
COL_CLIENT_NAME = "name"
COL_CLIENT_MIDDLE_NAME = "middle_name"

# =============================================================================
# LOAN BUSINESS RULES
# =============================================================================

ACTIVE_STATUSES = {"initial"}       # contracts.status values that mean active/disbursed

PAYMENT_TYPE_CLASSIC = "classic"    # Interest-only; principal paid at end
PAYMENT_TYPE_AMORTIZED = "amortized"  # Declining balance; principal paid monthly

# =============================================================================
# CALCULATION SETTINGS
# =============================================================================

ROUNDING_DECIMALS = 2               # Decimal places for interest amounts (AMD)
TOLERANCE = 0.1                     # Any difference above this is flagged

# =============================================================================
# LOGGING
# =============================================================================

LOG_LEVEL = "INFO"                  # DEBUG for verbose output, INFO for normal
LOG_FORMAT = "%(asctime)s [%(levelname)s] %(message)s"
