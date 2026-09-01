<footer class="footer mt-5 pt-4 pb-2 border-top shadow-lg" style="background: linear-gradient(90deg, #90caf9 0%, #ffe0b2 100%); color: #222; border-color: #eee !important; font-family: 'Montserrat', Arial, sans-serif; box-shadow: 0 -4px 24px 0 rgba(0,0,0,0.07);">
    <div class="container">
        <!-- Banners destacados en el pie -->
        <div class="row justify-content-center mb-2">
            <div class="col-lg-10">
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-center align-items-stretch">
                    <!-- Instagram -->
                    <div class="footer-banner d-flex align-items-center gap-2 flex-grow-1" style="background: linear-gradient(90deg, #fdf6ee 0%, #e3f2fd 100%); border: 1.5px solid #fd7e14; border-radius: 1.5em; padding: 0.7em 1.2em; min-width: 0;">
                        <span style="font-size: 1.7rem; background: linear-gradient(45deg, #f09433 0%,#e6683c 25%,#dc2743 50%,#cc2366 75%,#bc1888 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: flex; align-items: center;"><i class="bi bi-instagram"></i></span>
                        <span style="font-size: 1.02rem; color: #222;">
                            Tecnología, periféricos y soluciones para tu equipo.
                        </span>
                    </div>
                    <!-- WhatsApp -->
                    <div class="footer-banner d-flex align-items-center gap-2 flex-grow-1" style="background: linear-gradient(90deg, #e3f2fd 0%, #e0f7fa 100%); border: 1.5px solid #25d366; border-radius: 1.5em; padding: 0.7em 1.2em; min-width: 0;">
                        <span style="font-size: 1.7rem; color: #25d366; display: flex; align-items: center;"><i class="bi bi-whatsapp"></i></span>
                        <span style="font-size: 1.02rem; color: #222;">
                            Escribinos por cualquier consulta o para coordinar tu compra:<br>
                            <a href="https://wa.me/<?= htmlspecialchars(WHATSAPP_NUMBER) ?>?text=<?= urlencode('Hola ' . STORE_NAME . ', quisiera hacer una consulta.') ?>" target="_blank" rel="noopener" style="color: #128c7e; font-weight: 600; text-decoration: underline;">Contactar por WhatsApp</a>
                        </span>
                    </div>
                    <!-- Email -->
                    
                </div>
            </div>
        </div>
        <!-- Fin banners destacados en el pie -->
        <div class="row">
            <div class="col text-center">
                <small class="footer-copyright">&copy; <?php echo date('Y'); ?> <?= htmlspecialchars(STORE_NAME) ?>. Todos los derechos reservados.</small>
            </div>
        </div>
    </div>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&display=swap" rel="stylesheet">
    <style>
        .footer, .footer * {
            font-family: 'Montserrat', Arial, sans-serif !important;
        }
        .footer {
            background: linear-gradient(90deg, #90caf9 0%, #ffe0b2 100%) !important;
            color: #222;
            border-top: 1px solid #eee !important;
            box-shadow: 0 -4px 24px 0 rgba(0,0,0,0.07);
        }
        .footer-social {
            color: #222;
            font-size: 1.08rem;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .footer-social .footer-icon-bg {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            font-size: 1.5rem;
            background: #fff;
            border: 1.5px solid #fd7e14;
            margin-bottom: 2px;
            color: #fd7e14;
            transition: background 0.2s, color 0.2s, box-shadow 0.2s;
        }
        .footer-social.footer-ig .footer-icon-bg { color: #dc2743; border-color: #dc2743; }
        .footer-social.footer-wa .footer-icon-bg { color: #128c7e; border-color: #128c7e; }
        .footer-social.footer-mail .footer-icon-bg { color: #fd7e14; border-color: #fd7e14; }
        .footer-social:hover {
            color: #fd7e14;
        }
        .footer-social:hover .footer-icon-bg {
            background: #fff7ef;
            box-shadow: 0 2px 8px rgba(253,126,20,0.13);
        }
        .footer-social-text {
            font-size: 1.08rem;
            font-weight: 500;
            letter-spacing: 0.1px;
        }
        .footer-copyright {
            color: #444;
            font-size: 1.02rem;
            font-weight: 400;
            letter-spacing: 0.2px;
        }
        @media (max-width: 767px) {
            .footer-social-text {
                display: none;
            }
            .footer-icon-bg {
                margin-right: 0;
            }
        }
    </style>
</footer>
