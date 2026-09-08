import { Button, TextControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const config = window.iconLibraryAdmin;

function RenameModal( { items, closeModal, onDone } ) {
	const item = items[ 0 ];
	const [ label, setLabel ] = useState( item.label );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = async () => {
		if ( ! label.trim() ) {
			setError( __( 'An icon label is required.', 'icon-library' ) );
			return;
		}
		setIsSaving( true );
		setError( '' );
		try {
			await apiFetch( {
				path: `${ config.customPath }/${ encodeURIComponent( item.name ) }`,
				method: 'PATCH',
				data: { label: label.trim() },
			} );
			onDone();
			closeModal();
		} catch ( requestError ) {
			setError( requestError?.message || __( 'The icon could not be renamed.', 'icon-library' ) );
			setIsSaving( false );
		}
	};

	return (
		<>
			<TextControl label={ __( 'Label', 'icon-library' ) } value={ label } onChange={ setLabel } autoFocus __nextHasNoMarginBottom />
			{ error && <p className="icon-library-dataviews-error" role="alert">{ error }</p> }
			<div className="icon-library-dataviews-modal-actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isSaving }>{ __( 'Cancel', 'icon-library' ) }</Button>
				<Button variant="primary" onClick={ save } isBusy={ isSaving } disabled={ isSaving }>{ __( 'Save', 'icon-library' ) }</Button>
			</div>
		</>
	);
}

function DeleteModal( { items, closeModal, onDone } ) {
	const item = items[ 0 ];
	const [ isDeleting, setIsDeleting ] = useState( false );
	const [ error, setError ] = useState( '' );

	const remove = async () => {
		setIsDeleting( true );
		setError( '' );
		try {
			await apiFetch( { path: `${ config.customPath }/${ encodeURIComponent( item.name ) }`, method: 'DELETE' } );
			onDone();
			closeModal();
		} catch ( requestError ) {
			setError( requestError?.message || __( 'The icon could not be deleted.', 'icon-library' ) );
			setIsDeleting( false );
		}
	};

	return (
		<>
			<p>{ __( 'This permanently deletes the icon and its SVG file. Existing blocks using it will no longer render.', 'icon-library' ) }</p>
			{ error && <p className="icon-library-dataviews-error" role="alert">{ error }</p> }
			<div className="icon-library-dataviews-modal-actions">
				<Button variant="tertiary" onClick={ closeModal } disabled={ isDeleting }>{ __( 'Cancel', 'icon-library' ) }</Button>
				<Button variant="primary" isDestructive onClick={ remove } isBusy={ isDeleting } disabled={ isDeleting }>{ __( 'Delete', 'icon-library' ) }</Button>
			</div>
		</>
	);
}

function CustomIconsDataView() {
	const [ view, setView ] = useState( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		filters: [],
		fields: [ 'preview', 'label', 'iconName' ],
		sort: { field: 'label', direction: 'asc' },
		layout: {
			styles: {
				preview: { width: '96px', minWidth: '96px', maxWidth: '96px' },
				iconName: { width: '42%', minWidth: '220px' },
			},
		},
	} );
	const [ data, setData ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ refresh, setRefresh ] = useState( 0 );

	useEffect( () => {
		let active = true;
		setIsLoading( true );
		const query = new URLSearchParams( {
			page: String( view.page || 1 ),
			per_page: String( view.perPage || 20 ),
			search: view.search || '',
			orderby: view.sort?.field === 'name' ? 'name' : 'label',
			order: view.sort?.direction || 'asc',
		} );
		apiFetch( { path: `${ config.customPath }?${ query.toString() }` } )
			.then( ( response ) => {
				if ( active ) {
					setData( response.items );
					setTotal( response.total );
				}
			} )
			.catch( ( error ) => {
				if ( active ) {
					setData( [] );
					setTotal( 0 );
					window.console.error( error );
				}
			} )
			.finally( () => active && setIsLoading( false ) );
		return () => { active = false; };
	}, [ view.page, view.perPage, view.search, view.sort?.field, view.sort?.direction, refresh ] );

	const fields = useMemo( () => [
		{
			id: 'preview',
			label: __( 'Preview', 'icon-library' ),
			render: ( { item } ) => <span className="icon-library-dataviews-preview" aria-hidden="true" dangerouslySetInnerHTML={ { __html: item.svg } } />,
		},
		{ id: 'label', label: __( 'Label', 'icon-library' ), enableGlobalSearch: true, enableSorting: true },
		{
			id: 'iconName',
			label: __( 'Icon name', 'icon-library' ),
			enableGlobalSearch: true,
			render: ( { item } ) => <code className="icon-library-dataviews-name" title={ item.iconName }>{ item.iconName }</code>,
		},
	], [] );

	const changed = useCallback( () => setRefresh( ( value ) => value + 1 ), [] );
	const actions = useMemo( () => [
		{
			id: 'rename',
			label: __( 'Rename', 'icon-library' ),
			modalHeader: __( 'Rename icon', 'icon-library' ),
			isPrimary: true,
			supportsBulk: false,
			RenderModal: ( props ) => <RenameModal { ...props } onDone={ changed } />,
		},
		{
			id: 'delete',
			label: __( 'Delete', 'icon-library' ),
			modalHeader: __( 'Delete icon', 'icon-library' ),
			isPrimary: true,
			isDestructive: true,
			supportsBulk: false,
			RenderModal: ( props ) => <DeleteModal { ...props } onDone={ changed } />,
		},
	], [ changed ] );

	return (
		<DataViews
			data={ data }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			actions={ actions }
			isLoading={ isLoading }
			paginationInfo={ { totalItems: total, totalPages: Math.max( 1, Math.ceil( total / ( view.perPage || 20 ) ) ) } }
			search
			searchLabel={ __( 'Search icons', 'icon-library' ) }
			defaultLayouts={ { table: {} } }
		/>
	);
}

window.iconLibraryMountCustomDataViews = () => {
	const root = document.getElementById( 'icon-library-custom-dataviews' );
	if ( root && ! root.dataset.mounted ) {
		root.dataset.mounted = 'true';
		wp.element.createRoot( root ).render( <CustomIconsDataView /> );
	}
};

window.iconLibraryMountCustomDataViews();
