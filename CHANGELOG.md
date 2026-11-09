# Journal des versions

## 1.3.2
- Correction du bilan des sites : le site pilote lisait mal son propre état (ligne « ? : v, 0 produits suivis »).

## 1.3.1
- Alerte quotidienne : dès qu'un produit passe le seuil (sans offre ou écart de prix), un e-mail le signale, une seule fois par produit et au plus un e-mail par jour. Les produits déjà signalés ne reviennent que dans la synthèse du lundi.

## 1.3.0
- Alerte quand l'actualisation ne s'exécute plus du tout (WP-Cron bloqué) : contrôle à la fin des visites du site, au plus un e-mail par jour, après 3 heures.
- Site pilote : un seul bilan hebdomadaire (lundi) des 6 sites, même sans alerte (version, état, cron, produits à surveiller), à partir des adresses de contrôle. Bouton « Envoyer le bilan des sites maintenant ».

## 1.2.5
- Adresse de contrôle (lecture seule, protégée par une clé propre à chaque site) : version du plugin, dernière actualisation, retard du cron, produits à surveiller, dernier e-mail de synthèse. Visible dans les réglages, avec un bouton pour changer la clé.

## 1.2.4
- Intervalle d'actualisation des prix réglable (10, 15, 20, 30, 45 ou 55 minutes ; 30 par défaut). Le changement replanifie la tâche automatiquement.

## 1.2.3
- Bouton « Envoyer la synthèse maintenant » : envoi immédiat à l'adresse d'alerte, même sans alerte, pour tester la réception.
- Le résultat de l'envoi (accepté ou refusé par le serveur d'envoi, destinataire, motif) est enregistré et affiché dans l'état du plugin.

## 1.2.2
- Tableau « Prix suivis » : l'icône vers l'article en ligne reste visible même quand le titre est tronqué ; la date de relevé tient sur une seule ligne.

## 1.2.1
- Tableau « Prix suivis » : code couleur par état (vert à jour, orange à surveiller, rouge problème, gris pas encore relevé), pastilles de filtre avec compteurs, filtre par article, recherche par produit ou ASIN, tri par clic sur les en-têtes. Les problèmes s'affichent en premier.
- La cellule « Écart » est colorée quand l'écart atteint le seuil réglé ; l'état de la ligne n'en dépend pas.

## 1.2.0
- Tableau « Prix suivis » : nouvelle colonne « Produit » (nom lu dans l'image du lien Amazon de l'article), titres d'articles raccourcis (coupés au premier « : »), lien vers l'éditeur et lien vers l'article en ligne, « +N autres » quand un produit est dans plus de 2 articles.

## 1.1.1
- Version de contrôle du mécanisme de mise à jour automatique (aucun changement fonctionnel).

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
