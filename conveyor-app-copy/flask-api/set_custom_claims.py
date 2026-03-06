# flask_app/set_custom_claims.py
import firebase_admin
from firebase_admin import credentials, auth

# Inisialisasi aplikasi Firebase Admin SDK
cred = credentials.Certificate(
    "serviceAccountKey.json"
)  # Path ke service account key Firebase
firebase_admin.initialize_app(cred)


def set_custom_user_claims(uid, role, dcName=None):
    custom_claims = {"role": role}
    if dcName:
        custom_claims["dcName"] = dcName

    auth.set_custom_user_claims(uid, custom_claims)
    print(f"Custom claims set for user {uid}: {custom_claims}")


# Contoh penggunaan
if __name__ == "__main__":
    uid = "user_uid_here"  # Ganti dengan UID user
    role = "dc"  # atau 'admin'
    dcName = "DC-A"  # Hanya untuk role 'dc'
    set_custom_user_claims(uid, role, dcName)
