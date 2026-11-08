# Journal des versions

## 1.1.0
- Mises à jour automatiques depuis GitHub, avec contrôle d'empreinte SHA-256 avant installation.
- Textes réglables : texte de repli (« Voir l’offre » par défaut), libellé du lien d'avis, avis en bas de page.
- Produits à surveiller : produits sans offre depuis N jours, avec les articles concernés.
- Alerte d'écart de prix par rapport au prix de référence (premier prix relevé).
- E-mail de synthèse hebdomadaire (seulement s'il y a quelque chose à signaler) et adresse d'alerte réglable.
- Contrôle de santé : cron en retard ou désactivé, requêtes API du jour sur le quota, version et mise à jour disponible.
- Conseil de sécurité quand le secret est enregistré en base (identifiants possibles dans wp-config.php).

## 1.0.1
- Texte de repli « Voir l’offre » (au lieu de « Voir le prix sur Amazon »).

## 1.0.0
- Première version : shortcode [prix asin="..."], actualisation toutes les 30 minutes, repli automatique.
