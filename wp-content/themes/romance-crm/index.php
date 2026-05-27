<?php
/**
 * The main template file
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package romance-crm
 */

get_header();

if ( !is_user_logged_in() ) : ?>
	<style>
		.header, .footer{
			display: none!important;
		}
		body {
    background: #222D32;
    font-family: 'Roboto', sans-serif;
		}

		.login-box {
				margin-top: 75px;
				height: auto;
				background: #1A2226;
				text-align: center;
				box-shadow: 0 3px 6px rgba(0, 0, 0, 0.16), 0 3px 6px rgba(0, 0, 0, 0.23);
		}

		.login-key {
				height: 100px;
				font-size: 80px;
				line-height: 100px;
				background: -webkit-linear-gradient(#27EF9F, #0DB8DE);
				-webkit-background-clip: text;
				-webkit-text-fill-color: transparent;
		}

		.login-title {
				margin-top: 15px;
				text-align: center;
				font-size: 30px;
				letter-spacing: 2px;
				margin-top: 15px;
				font-weight: bold;
				color: #ECF0F5;
		}

		.login-form {
				margin-top: 25px;
				text-align: left;
		}

		input[type=email] {
				background-color: #1A2226;
				border: none;
				border-bottom: 2px solid #fff;
				border-top: 0px;
				border-radius: 0px;
				font-weight: bold;
				outline: 0;
				margin-bottom: 20px;
				padding-left: 0px;
				color: #fff;
		}

		input[type=password] {
				background-color: #1A2226;
				border: none;
				border-bottom: 2px solid #fff;
				border-top: 0px;
				border-radius: 0px;
				font-weight: bold;
				outline: 0;
				padding-left: 0px;
				margin-bottom: 20px;
				color: #fff;
		}

		.form-group {
				margin-bottom: 40px;
				outline: 0px;
		}

		.form-control:focus {
				border-color: inherit;
				-webkit-box-shadow: none;
				box-shadow: none;
				border-bottom: 2px solid #fff;
				outline: 0;
				background-color: #1A2226;
				color: #fff!important;
		}

		input:focus {
				outline: none;
				box-shadow: 0 0 0;
		}

		label {
				margin-bottom: 0px;
		}

		.form-control-label {
				font-size: 10px;
				color: #6C6C6C;
				font-weight: bold;
				letter-spacing: 1px;
		}

		.btn-outline-primary {
				border-color: #fff;
				color: #fff;
				border-radius: 0px;
				font-weight: bold;
				letter-spacing: 1px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
		}

		.btn-outline-primary:hover {
				background-color: #fff;
				color: #000!important;
				right: 0px;
				border-color: #fff!important;
		}

		.login-btm {
				float: left;
		}

		.login-button {
				padding-right: 0px;
				text-align: right;
				
		}

		.login-text {
				text-align: left;
				padding-left: 0px;
				color: #d30c0c;
		}

		.loginbttm {
				padding: 0px;
		}
	</style>
	<section class="testimonial-section mt-100 pt-80 pb-80">
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-lg-6 col-md-8 login-box">
					<div class="col-lg-12 login-key text-center mb-3">
						<i class="fa fa-key" aria-hidden="true"></i>
					</div>
					<div class="col-lg-12 login-title text-center mb-4">
						CRM СИСТЕМА
					</div>

					<div class="col-lg-12 login-form">
						<form method="post" action="#">
							<div class="form-group">
								<label class="form-control-label" for="username">EMAIL</label>
								<input type="email" class="form-control" id="username" name="log" required>
							</div>
							<div class="form-group">
								<label class="form-control-label" for="password">ПАРОЛЬ</label>
								<input type="password" class="form-control" id="password" name="pwd" required>
							</div>

							<div class="col-lg-12 loginbttm d-flex justify-content-between align-items-center mt-3 mb-3">
								<div class="login-text"></div>
								<div class="login-button">
									<button type="submit" class="btn btn-outline-primary">ВОЙТИ</button>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</section>
<?php else : ?>
	<section class="testimonial-section mt-70 pt-80 pb-80">
		<div class="container">
			<?php if(is_front_page()) { ?>
				<h3 class="text-center mb-5">Список моделей</h3>
				<form class="search d-flex gap-2 mb-4" action="" method="get">
					<input type="hidden" name="source" value="<?php echo esc_attr( $_GET['source'] ?? 'all' ); ?>">
					<input type="text" name="search" value="<?php if (!empty($_GET['search'])) {echo esc_attr($_GET['search']);} ?>" required placeholder="Введите ID модели">
					<button type="submit">Поиск</button>
					<?php
						if (!empty($_GET['search'])) {
							$clear_url = home_url( '/' . ( ! empty( $_GET['source'] ) && $_GET['source'] !== 'all' ? '?source=' . esc_attr( $_GET['source'] ) : '' ) );
							echo '<a style="margin-top: auto;margin-bottom: auto;" href="'. esc_url( $clear_url ) .'" class="text-dark">Очистить поиск</a>';
						}
					?>
				</form>
				<?php
				$source_filter = sanitize_text_field( $_GET['source'] ?? 'all' );
				if ( ! in_array( $source_filter, [ 'all', 'romance_compass', 'dating_com' ], true ) ) {
					$source_filter = 'all';
				}
				$search_qs = ! empty( $_GET['search'] ) ? '&search=' . urlencode( $_GET['search'] ) : '';
				?>
				<div class="source-filter-tabs mb-3">
					<a href="<?= esc_url( home_url( '/?source=all' . $search_qs ) ); ?>"
					   class="filter-btn<?= $source_filter === 'all' ? ' active-all' : ''; ?>">Все</a>
					<a href="<?= esc_url( home_url( '/?source=romance_compass' . $search_qs ) ); ?>"
					   class="filter-btn<?= $source_filter === 'romance_compass' ? ' active-rc' : ''; ?>">RomanceCompass</a>
					<a href="<?= esc_url( home_url( '/?source=dating_com' . $search_qs ) ); ?>"
					   class="filter-btn<?= $source_filter === 'dating_com' ? ' active-dc' : ''; ?>">Dating.com</a>
				</div>
				<div class="models d-grid gap-3" id="model-list">
				<?php
				$current_user = wp_get_current_user();
				$current_user_id = get_current_user_id();

				// Базовый запрос (для подсчёта общего количества постов)
				$args = array(
						'post_type' => 'model',
						'posts_per_page' => -1,
						'meta_query' => array()
				);

				if (!in_array('manager', (array) $current_user->roles) && !in_array('administrator', (array) $current_user->roles)) {
						$args['meta_query'][] = array(
								'key' => 'user_model',
								'value' => '"' . $current_user_id . '"',
								'compare' => 'LIKE'
						);
				}

				if (!empty($_GET['search'])) {
						$search_value = sanitize_text_field($_GET['search']);
						$args['meta_query'][] = array(
								'key' => 'id_model',
								'value' => $search_value,
								'compare' => '='
						);
				}

				if ( $source_filter === 'dating_com' ) {
					$args['meta_query'][] = array(
						'key'     => 'source_model',
						'value'   => 'dating_com',
						'compare' => '=',
					);
				} elseif ( $source_filter === 'romance_compass' ) {
					$args['meta_query'][] = array(
						'relation' => 'OR',
						array(
							'key'     => 'source_model',
							'value'   => 'romance_compass',
							'compare' => '=',
						),
						array(
							'key'     => 'source_model',
							'compare' => 'NOT EXISTS',
						),
					);
				}
				
				$total_query = new WP_Query($args);
				$total_posts = $total_query->found_posts;
				wp_reset_postdata();
				
				// Основной вывод (первая партия)
				$args['posts_per_page'] = 10;
				$args['offset'] = 0;
				$query = new WP_Query($args);

				if ($query->have_posts()) :
						while ($query->have_posts()) : $query->the_post();

						$blocked = get_post_meta(get_the_ID(), '_blocked-use', true);
						$blocked_time = get_post_meta(get_the_ID(), '_blocked-time', true);
						$blocked_by = get_post_meta(get_the_ID(), '_blocked-by-user', true);

						if ($blocked && $blocked_time && $blocked_time > time()) {
							$class = 'blocked';
							$btn = '<span class="a" disabled="disabled">Модель занята</span>';
						} else {
							$class = '';
							$btn = '<a target="_blank" href="' . esc_url(get_permalink()) . '">Работать с моделью</a>';
						}
						$src         = get_field('source_model') ?: 'romance_compass';
						$badge_label = $src === 'dating_com' ? 'Dating.com' : 'RomanceCompass';
						$badge_class = $src === 'dating_com' ? 'badge-dating' : 'badge-rc';
						?>
								<div class="model-item p-4 rounded <?= $class; ?>">
										<div class="d-flex gap-3">
												<div class="image">
														<img src="<?= esc_url(get_field('avatar_model')); ?>">
												</div>
												<div class="info">
														<h6 class="title"><?= esc_html(get_field('name_model')); ?>
																<span class="id" style="color: #0000005c;">(ID: <?= esc_html(get_field('id_model')); ?>)</span>
																<span class="source-badge <?= esc_attr($badge_class); ?>"><?= esc_html($badge_label); ?></span>
														</h6>
														<p class="subtitle mt-1 mb-1">
																Страна: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('country_model')); ?></span> |
																Возраст: <span style="font-weight: bold;color: rgba(0,0,0,0.7);"><?= esc_html(get_field('years_model')); ?></span>
														</p>
														<?= $btn; ?>
												</div>
										</div>
								</div>
				<?php
						endwhile;
				endif;
				wp_reset_postdata();
				?>
				</div>
				<?php if ($total_posts > 10): ?>
					<button id="load-more"
						class="btn btn-outline-primary mt-4 mb-4"
						style="margin: auto;display: block;"
						data-offset="10"
						data-total="<?= $total_posts ?>"
						data-search="<?= esc_attr($_GET['search'] ?? '') ?>"
						data-source="<?= esc_attr($source_filter) ?>">
						Показать ещё
					</button>
				<?php endif; ?>
			<?php } else if(is_singular('model')) { ?>
				<?php
				$current_user_id = get_current_user_id();
				$current_user = wp_get_current_user();
				$operators = get_field('user_model');

				if (in_array('manager', (array) $current_user->roles) || in_array('administrator', (array) $current_user->roles)) {
					// просто пропускаем проверку
				} else {
						// Проверка, что $operators — массив, и что ID текущего пользователя в нем есть
						if (!is_array($operators) || !in_array($current_user_id, $operators)) {
								$message = "<div class='bg-danger text-white text-center p-2 notice-model-page'><strong>Ошибка:</strong> у вас нет доступа к этой модели.</div>";
								die($message);
						}
				}

				$blocked = get_post_meta(get_the_ID(), '_blocked-use', true);
				$blocked_time = get_post_meta(get_the_ID(), '_blocked-time', true);
				$blocked_by = get_post_meta(get_the_ID(), '_blocked-by-user', true);

				// Якщо блок активний і ще не минув
				if ($blocked && $blocked_time && $blocked_time > time() && $blocked_by != $current_user_id) {
						$user_info = get_userdata($blocked_by);
						$user_name = $user_info->display_name ?: $user_info->user_email;

						$time_left = $blocked_time - time();
						$minutes_left = ceil($time_left / 60);

						$message = "<div class='bg-danger text-white text-center p-2 notice-model-page'>С моделью уже работает пользователь <strong>{$user_name}</strong>.</br>
						Попробуйте снова через <strong>{$minutes_left} мин.</strong></div>";

						die($message);
				}
				$log_message = 'Открыл страницу модели <a href="'. get_the_permalink(get_the_ID()) .'">'. get_the_title(get_the_ID()) . '</a>';
				set_log('open_model', $log_message);
				?>

				<script type="text/javascript">
					var model_id = "<?= the_field('id_model'); ?>";
				</script>
				<div class="breadcrumbs">
					<a href="<?= home_url('/'); ?>">Все модели</a>
					<span>/</span>
					<span class="current"><?= the_field('name_model'); ?> <span class="id" style="color: #0000005c;">(ID: <?= the_field('id_model'); ?>)</span></span>
				</div>
				<div class="cont d-grid mt-5 mb-5 gap-5">
					<div style="box-shadow: rgba(149, 157, 165, 0.2) 0px 8px 24px;" class="infos pt-4 pb-4 ps-3 pe-3 rounded">
						<div class="model-info d-flex mb-3 gap-3">
							<div class="img">
								<img src="<?php the_field('avatar_model'); ?>" alt="Фото модели" class="img-fluid rounded">
							</div>
							<div class="information d-grid">
								<div>
									<h5 class="name"><?= the_field('name_model'); ?> <span class="id" style="color: #0000005c;">(ID: <?= the_field('id_model'); ?>)</span></h5>
									<p class="mt-2 fs">Возраст: <strong><?php the_field('years_model'); ?></strong></p>
									<p class="fs">Страна: <strong><?php the_field('country_model'); ?></strong></p>
									<p class="fs">Профессия: <strong><?php the_field('profession_model'); ?></strong></p>
								</div>
								<div>
									<?php if (have_rows('interess_model')) : ?>
										<p class="fs">Интересы:
											<?php while (have_rows('interess_model')) : the_row(); ?>
												<strong>
													<?php the_sub_field('name'); ?>
												</strong>
											<?php endwhile; ?>
										</p>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<div>
						<?php echo strip_tags(get_field('info_model'), '<p><span><br><strong><em><del>'); ?>
						</div>
					</div>
					<div class="actions pt-4 pb-4 ps-3 pe-3 rounded" style="box-shadow: rgba(149, 157, 165, 0.2) 0px 8px 24px;max-height: 380px;height: auto;">
						<?php
						$model_source       = get_field('source_model') ?: 'romance_compass';
						$contact_list_label = $model_source === 'dating_com'
							? 'Список контактов — Dating.com'
							: 'Список контактов';
						?>
						<div class="contact-list mb-3">
							<h6 class="text-center p-2 bg-secondary bg-gradient text-white"><?= esc_html($contact_list_label); ?></h6>
							<div class="response">
								<p class="text-center">Авторизация...</p>
							</div>
						</div>
						<div class="buttons">
						<?php if ( $model_source !== 'dating_com' ) : ?>
						<button id="goSpam" class="btn btn-outline-primary" style="margin: auto;display: block;">Рассылка</button>
						<?php endif; ?>
						</div>
					</div>
				</div>
			<?php if ( $model_source === 'dating_com' ) : ?>
					<?= dc_render_sync_panel( get_the_ID() ); ?>
				<?php endif; ?>
			<?php } ?>
		</div>
	</section>
<?php endif;

get_footer();

